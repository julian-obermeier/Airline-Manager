<?php

namespace App\Services\Simulation;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightSimulationService
{
    public function tick(?Carbon $realNow = null): array
    {
        $realNow ??= now();
        $summary = [
            'worlds' => 0,
            'flights_checked' => 0,
            'boarding' => 0,
            'departed' => 0,
            'in_air' => 0,
            'completed' => 0,
        ];

        World::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (World $world) use ($realNow, &$summary): void {
                $simulationNow = $this->advanceWorldClock($world, $realNow);
                $summary['worlds']++;

                $flights = Flight::query()
                    ->with(['route.destination', 'aircraft.type', 'airline'])
                    ->where('world_id', $world->id)
                    ->whereIn('status', ['scheduled', 'boarding', 'departed', 'in_air'])
                    ->where('scheduled_departure_at', '<=', $simulationNow->copy()->addMinutes(config('simulation.boarding_minutes', 30)))
                    ->orderBy('scheduled_departure_at')
                    ->get();

                foreach ($flights as $flight) {
                    $summary['flights_checked']++;
                    $result = $this->processFlight($flight, $simulationNow);

                    if ($result && array_key_exists($result, $summary)) {
                        $summary[$result]++;
                    }
                }
            });

        return $summary;
    }

    private function advanceWorldClock(World $world, Carbon $realNow): Carbon
    {
        if (! $world->last_simulation_tick_at) {
            $simulationNow = $realNow->copy();
        } else {
            $elapsedSeconds = max(0, $world->last_simulation_tick_at->diffInSeconds($realNow, false));
            $speed = max(0.01, (float) $world->speed_multiplier);
            $simulationNow = ($world->simulated_at ?? $realNow)
                ->copy()
                ->addSeconds((int) round($elapsedSeconds * $speed));
        }

        $world->forceFill([
            'simulated_at' => $simulationNow,
            'last_simulation_tick_at' => $realNow,
        ])->save();

        return $simulationNow;
    }

    private function processFlight(Flight $flight, Carbon $simulationNow): ?string
    {
        $boardingAt = $flight->scheduled_departure_at->copy()->subMinutes(config('simulation.boarding_minutes', 30));
        $departureAt = $flight->scheduled_departure_at->copy()->addMinutes($flight->delay_minutes);
        $arrivalAt = $flight->scheduled_arrival_at->copy()->addMinutes($flight->delay_minutes);

        if ($simulationNow->greaterThanOrEqualTo($arrivalAt)) {
            return $this->completeFlight($flight, $departureAt, $arrivalAt) ? 'completed' : null;
        }

        if ($simulationNow->greaterThanOrEqualTo($departureAt->copy()->addMinutes(10))) {
            $this->ensureDemand($flight);
            $this->markDeparted($flight, $departureAt);

            if ($flight->status !== 'in_air') {
                $flight->forceFill(['status' => 'in_air'])->save();
                return 'in_air';
            }

            return null;
        }

        if ($simulationNow->greaterThanOrEqualTo($departureAt)) {
            $this->ensureDemand($flight);

            return $this->markDeparted($flight, $departureAt) ? 'departed' : null;
        }

        if ($simulationNow->greaterThanOrEqualTo($boardingAt) && $flight->status === 'scheduled') {
            $this->ensureDemand($flight);
            $flight->forceFill(['status' => 'boarding'])->save();

            return 'boarding';
        }

        return null;
    }

    private function ensureDemand(Flight $flight): void
    {
        if ($flight->passengers_booked > 0 || data_get($flight->operational_data, 'demand_generated')) {
            return;
        }

        $flight->loadMissing(['aircraft.type', 'airline']);

        $seats = max(1, (int) data_get($flight->aircraft?->configuration, 'seats', $flight->aircraft?->type?->typical_seats ?? 1));
        $businessModel = $flight->airline?->business_model ?? 'hybrid';
        $baseLoadFactor = match ($businessModel) {
            'low_cost' => 0.79,
            'full_service' => 0.72,
            'regional' => 0.70,
            'cargo' => 0.35,
            default => 0.75,
        };

        $variation = (abs(crc32($flight->id)) % 1600) / 10000;
        $loadFactor = min(0.95, max(0.50, $baseLoadFactor + $variation));
        $passengers = min($seats, max(1, (int) round($seats * $loadFactor)));

        $data = $flight->operational_data ?? [];
        $data['demand_generated'] = true;
        $data['load_factor'] = round($passengers / $seats, 4);
        $data['seat_capacity'] = $seats;

        $flight->forceFill([
            'passengers_booked' => $passengers,
            'operational_data' => $data,
        ])->save();
    }

    private function markDeparted(Flight $flight, Carbon $departureAt): bool
    {
        if (in_array($flight->status, ['departed', 'in_air', 'completed'], true)) {
            return false;
        }

        DB::transaction(function () use ($flight, $departureAt): void {
            $locked = Flight::query()->lockForUpdate()->findOrFail($flight->id);

            if (in_array($locked->status, ['departed', 'in_air', 'completed'], true)) {
                return;
            }

            $locked->forceFill([
                'status' => 'departed',
                'actual_departure_at' => $locked->actual_departure_at ?? $departureAt,
            ])->save();

            if ($locked->aircraft_id) {
                Aircraft::query()->whereKey($locked->aircraft_id)->update([
                    'status' => 'in_flight',
                    'current_airport_id' => null,
                ]);
            }
        });

        $flight->refresh();

        return $flight->status === 'departed';
    }

    private function completeFlight(Flight $flight, Carbon $departureAt, Carbon $arrivalAt): bool
    {
        $completed = false;

        DB::transaction(function () use ($flight, $departureAt, $arrivalAt, &$completed): void {
            $locked = Flight::query()
                ->with(['route.destination', 'aircraft.type', 'airline'])
                ->lockForUpdate()
                ->findOrFail($flight->id);

            if ($locked->status === 'completed') {
                return;
            }

            $this->ensureDemand($locked);

            if (! $locked->actual_departure_at) {
                $locked->forceFill(['actual_departure_at' => $departureAt])->save();
            }

            $economics = $this->calculateEconomics($locked);
            $this->postFlightEconomics($locked, $economics, $arrivalAt);

            $data = $locked->operational_data ?? [];
            $data['economics'] = $economics;
            $data['completed_at'] = $arrivalAt->toIso8601String();

            $locked->forceFill([
                'status' => 'completed',
                'actual_arrival_at' => $arrivalAt,
                'operational_data' => $data,
            ])->save();

            if ($locked->aircraft_id && $locked->aircraft) {
                $blockHours = max(0.01, $locked->scheduled_departure_at->diffInMinutes($locked->scheduled_arrival_at) / 60);
                $conditionLoss = 0.05 + ($blockHours * 0.015);
                $aircraft = $locked->aircraft;

                $aircraft->forceFill([
                    'current_airport_id' => $locked->route->destination_airport_id,
                    'status' => 'available',
                    'flight_hours' => round((float) $aircraft->flight_hours + $blockHours, 2),
                    'flight_cycles' => (int) $aircraft->flight_cycles + 1,
                    'condition_percent' => max(0, round((float) $aircraft->condition_percent - $conditionLoss, 2)),
                ])->save();
            }

            $completed = true;
        });

        return $completed;
    }

    private function calculateEconomics(Flight $flight): array
    {
        $distanceKm = (float) ($flight->route?->distance_km ?? data_get($flight->operational_data, 'distance_km', 0));
        $passengers = (int) $flight->passengers_booked;
        $businessModel = $flight->airline?->business_model ?? 'hybrid';

        $fareMultiplier = match ($businessModel) {
            'low_cost' => 0.82,
            'full_service' => 1.18,
            'regional' => 1.08,
            'cargo' => 0.55,
            default => 1.00,
        };

        $averageFareMinor = (int) round((3500 + ($distanceKm * 11)) * $fareMultiplier);
        $revenueMinor = $passengers * $averageFareMinor;

        $blockHours = max(0.25, $flight->scheduled_departure_at->diffInMinutes($flight->scheduled_arrival_at) / 60);
        $burnPerHour = (float) data_get($flight->aircraft?->type?->technical_data, 'fuel_burn_l_per_hour', 2400);
        $fuelLiters = (int) round(($blockHours * $burnPerHour) + 250);
        $fuelCostMinor = $fuelLiters * (int) config('simulation.fuel_price_minor_per_liter', 88);

        $seats = max(1, (int) data_get($flight->aircraft?->configuration, 'seats', $flight->aircraft?->type?->typical_seats ?? 1));
        $operatingCostMinor = (int) round(150000 + ($seats * 350) + ($distanceKm * 60));
        $profitMinor = $revenueMinor - $fuelCostMinor - $operatingCostMinor;

        return [
            'passengers' => $passengers,
            'load_factor' => (float) data_get($flight->operational_data, 'load_factor', 0),
            'average_fare_minor' => $averageFareMinor,
            'revenue_minor' => $revenueMinor,
            'fuel_liters' => $fuelLiters,
            'fuel_cost_minor' => $fuelCostMinor,
            'operating_cost_minor' => $operatingCostMinor,
            'profit_minor' => $profitMinor,
        ];
    }

    private function postFlightEconomics(Flight $flight, array $economics, Carbon $arrivalAt): void
    {
        $existing = LedgerTransaction::query()
            ->where('world_id', $flight->world_id)
            ->where('idempotency_key', 'flight-completion:'.$flight->id)
            ->exists();

        if ($existing) {
            return;
        }

        $airline = $flight->airline ?? Airline::findOrFail($flight->airline_id);
        $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
        $revenue = $this->account($airline, 'FLIGHT_REVENUE', 'Flugumsätze', 'income');
        $fuelExpense = $this->account($airline, 'FUEL_EXPENSE', 'Treibstoffkosten', 'expense');
        $operatingExpense = $this->account($airline, 'FLIGHT_OPERATING_EXPENSE', 'Flugbetriebskosten', 'expense');

        $transaction = LedgerTransaction::create([
            'world_id' => $flight->world_id,
            'airline_id' => $flight->airline_id,
            'idempotency_key' => 'flight-completion:'.$flight->id,
            'reference_type' => 'flight_completion',
            'reference_id' => $flight->id,
            'description' => 'Flugabschluss '.$flight->flight_number,
            'occurred_at' => $arrivalAt,
            'posted_at' => now(),
            'metadata' => $economics,
        ]);

        $netCashMinor = $economics['revenue_minor'] - $economics['fuel_cost_minor'] - $economics['operating_cost_minor'];

        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $cash->id,
            'amount_minor' => $netCashMinor,
            'memo' => 'Netto-Cashflow '.$flight->flight_number,
        ]);
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $revenue->id,
            'amount_minor' => -$economics['revenue_minor'],
            'memo' => 'Passagierumsatz',
        ]);
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $fuelExpense->id,
            'amount_minor' => $economics['fuel_cost_minor'],
            'memo' => 'Treibstoff',
        ]);
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $operatingExpense->id,
            'amount_minor' => $economics['operating_cost_minor'],
            'memo' => 'Handling, Crew und operative Kosten',
        ]);
    }

    private function account(Airline $airline, string $code, string $name, string $type): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            ['airline_id' => $airline->id, 'code' => $code],
            [
                'world_id' => $airline->world_id,
                'name' => $name,
                'type' => $type,
                'currency' => $airline->base_currency,
                'is_system' => true,
            ]
        );
    }
}
