<?php

namespace App\Services\Simulation;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Commercial\MarketingService;
use App\Services\Commercial\RevenueManagementService;
use App\Services\Operations\AirportOperationsService;
use App\Services\Operations\CrewService;
use App\Services\Operations\FlightScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightSimulationService
{
    public function __construct(
        private readonly RevenueManagementService $revenueManagement,
        private readonly FlightScheduleService $flightSchedules,
        private readonly CrewService $crewService,
        private readonly AirportOperationsService $airportOperations,
        private readonly MarketingService $marketing,
    ) {
    }

    public function tick(?Carbon $realNow = null): array
    {
        $realNow ??= now();
        $summary = [
            'worlds' => 0,
            'schedules_checked' => 0,
            'flights_generated' => 0,
            'rotations_skipped' => 0,
            'flights_checked' => 0,
            'bookings_updated' => 0,
            'delays_evaluated' => 0,
            'delayed_flights' => 0,
            'boarding' => 0,
            'departed' => 0,
            'in_air' => 0,
            'completed' => 0,
            'crew_cancelled' => 0,
            'campaigns_expired' => 0,
        ];

        World::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (World $world) use ($realNow, &$summary): void {
                $simulationNow = $this->advanceWorldClock($world, $realNow);
                $summary['worlds']++;

                $marketing = $this->marketing->processWorld($world, $simulationNow);
                $summary['campaigns_expired'] += (int) ($marketing['campaigns_expired'] ?? 0);

                $planning = $this->flightSchedules->generateForWorld($world, $simulationNow);
                $summary['schedules_checked'] += $planning['schedules'];
                $summary['flights_generated'] += $planning['flights_created'];
                $summary['rotations_skipped'] += $planning['rotations_skipped'];

                $bookingHorizon = $simulationNow->copy()->addDays(max(1, (int) config('simulation.booking_window_days', 14)));

                $flights = Flight::query()
                    ->with(['route.origin', 'route.destination', 'aircraft.type', 'airline'])
                    ->where('world_id', $world->id)
                    ->whereIn('status', ['scheduled', 'boarding', 'departed', 'in_air'])
                    ->where('scheduled_departure_at', '<=', $bookingHorizon)
                    ->orderBy('scheduled_departure_at')
                    ->get();

                foreach ($flights as $flight) {
                    $summary['flights_checked']++;

                    $evaluatedDelay = $this->ensureOperationalDelay($flight, $simulationNow);
                    if ($evaluatedDelay !== null) {
                        $summary['delays_evaluated']++;
                        if ($evaluatedDelay > 0) {
                            $summary['delayed_flights']++;
                        }
                    }

                    if (in_array($flight->status, ['scheduled', 'boarding'], true)
                        && $this->updateBookings($flight, $simulationNow)) {
                        $summary['bookings_updated']++;
                    }

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

        if (in_array($flight->status, ['scheduled', 'boarding'], true)
            && $simulationNow->greaterThanOrEqualTo($departureAt)
            && ! $this->crewService->readyForDeparture($flight)) {
            $this->crewService->cancelForShortage($flight);
            $this->marketing->recordCancellation($flight->fresh(), 'crew_shortage');

            return 'crew_cancelled';
        }

        if ($simulationNow->greaterThanOrEqualTo($arrivalAt)) {
            return $this->completeFlight($flight, $departureAt, $arrivalAt) ? 'completed' : null;
        }

        if ($simulationNow->greaterThanOrEqualTo($departureAt->copy()->addMinutes(10))) {
            $this->updateBookings($flight, $departureAt, true);
            $this->markDeparted($flight, $departureAt);

            if ($flight->status !== 'in_air') {
                $flight->forceFill(['status' => 'in_air'])->save();

                return 'in_air';
            }

            return null;
        }

        if ($simulationNow->greaterThanOrEqualTo($departureAt)) {
            $this->updateBookings($flight, $departureAt, true);

            return $this->markDeparted($flight, $departureAt) ? 'departed' : null;
        }

        if ($simulationNow->greaterThanOrEqualTo($boardingAt) && $flight->status === 'scheduled') {
            $this->updateBookings($flight, $simulationNow);
            $flight->forceFill(['status' => 'boarding'])->save();

            return 'boarding';
        }

        return null;
    }

    private function ensureOperationalDelay(Flight $flight, Carbon $simulationNow): ?int
    {
        if (! in_array($flight->status, ['scheduled', 'boarding'], true)) {
            return null;
        }

        $evaluationAt = $flight->scheduled_departure_at
            ->copy()
            ->subMinutes(max(30, (int) config('simulation.delay_evaluation_minutes', 180)));

        if ($simulationNow->lessThan($evaluationAt)) {
            return null;
        }

        $data = $flight->operational_data ?? [];
        if (data_get($data, 'operations.delay_evaluated')) {
            return null;
        }

        $flight->loadMissing(['aircraft.type']);
        $seed = (int) sprintf('%u', crc32($flight->id.':'.$flight->scheduled_departure_at->format('YmdHi')));
        $percentile = $seed % 100;
        $baseDelay = match (true) {
            $percentile < 62 => 0,
            $percentile < 82 => 5 + ($seed % 11),
            $percentile < 95 => 16 + ($seed % 20),
            default => 36 + ($seed % 40),
        };

        $condition = (float) ($flight->aircraft?->condition_percent ?? 100);
        $technicalPenalty = $condition < 95
            ? min(30, (int) ceil((95 - $condition) * 0.9))
            : 0;
        $delay = $baseDelay + $technicalPenalty;
        $rotationDelay = 0;

        if ($flight->aircraft_id && $flight->aircraft) {
            $previous = Flight::query()
                ->where('aircraft_id', $flight->aircraft_id)
                ->where('id', '!=', $flight->id)
                ->whereNotIn('status', ['cancelled'])
                ->where('scheduled_departure_at', '<', $flight->scheduled_departure_at)
                ->orderByDesc('scheduled_arrival_at')
                ->first();

            if ($previous) {
                $previousArrival = $previous->actual_arrival_at
                    ? $previous->actual_arrival_at->copy()
                    : $previous->scheduled_arrival_at->copy()->addMinutes((int) $previous->delay_minutes);
                $minimumTurnaround = $this->flightSchedules->minimumTurnaroundMinutes($flight->aircraft);
                $earliestDeparture = $previousArrival->addMinutes($minimumTurnaround);
                $currentPlannedDeparture = $flight->scheduled_departure_at->copy()->addMinutes($delay);

                if ($earliestDeparture->greaterThan($currentPlannedDeparture)) {
                    $rotationDelay = max(0, $flight->scheduled_departure_at->diffInMinutes($earliestDeparture, false));
                    $delay = max($delay, $rotationDelay);
                }
            }
        }

        $delay = min(240, max(0, $delay));
        $data['operations'] = array_merge($data['operations'] ?? [], [
            'delay_evaluated' => true,
            'delay_evaluated_at' => $simulationNow->toIso8601String(),
            'base_delay_minutes' => $baseDelay,
            'technical_delay_minutes' => $technicalPenalty,
            'rotation_delay_minutes' => $rotationDelay,
            'final_delay_minutes' => $delay,
        ]);

        $flight->forceFill([
            'delay_minutes' => $delay,
            'operational_data' => $data,
        ])->save();

        return $delay;
    }

    private function updateBookings(Flight $flight, Carbon $simulationNow, bool $finalize = false): bool
    {
        if (! $finalize && ! in_array($flight->status, ['scheduled', 'boarding'], true)) {
            return false;
        }

        $flight->loadMissing(['aircraft.type', 'airline', 'route']);

        if (! $flight->aircraft || ! $flight->airline || ! $flight->route) {
            return false;
        }

        $data = $flight->operational_data ?? [];
        $commercial = $data['commercial'] ?? [];
        $cabins = $commercial['cabins'] ?? null;
        $totalSeats = max(1, (int) data_get($flight->aircraft->configuration, 'seats', $flight->aircraft->type?->typical_seats ?? 1));

        if (! is_array($cabins) || $cabins === []) {
            $layout = $this->revenueManagement->cabinLayout($totalSeats, $flight->airline->business_model);
            $fares = $this->revenueManagement->routeFares($flight->route, $flight->airline->business_model);
            $cabins = [
                'economy' => ['capacity' => $layout['economy'], 'fare_minor' => $fares['economy_minor'], 'booked' => 0],
                'business' => ['capacity' => $layout['business'], 'fare_minor' => $fares['business_minor'], 'booked' => 0],
                'first' => ['capacity' => $layout['first'], 'fare_minor' => $fares['first_minor'], 'booked' => 0],
            ];
        }

        $referenceFares = $this->revenueManagement->defaultFares((float) $flight->route->distance_km, $flight->airline->business_model);
        $demandIndex = (float) ($commercial['route_demand_index'] ?? data_get($flight->route->settings, 'demand_index', 1.0));
        $progress = $finalize ? 1.0 : $this->bookingProgress($flight, $simulationNow);
        $dayFactor = in_array($flight->scheduled_departure_at->dayOfWeekIso, [5, 7], true) ? 1.06 : 1.00;
        $marketingSnapshot = $this->marketing->demandSnapshot($flight, $simulationNow);
        $marketingMultiplier = (float) ($marketingSnapshot['multiplier'] ?? 1.0);
        $previousPassengers = (int) $flight->passengers_booked;
        $totalBooked = 0;
        $totalCapacity = 0;

        foreach (['economy', 'business', 'first'] as $cabin) {
            $capacity = max(0, (int) data_get($cabins, $cabin.'.capacity', 0));
            $fareMinor = max(0, (int) data_get($cabins, $cabin.'.fare_minor', 0));
            $alreadyBooked = max(0, (int) data_get($cabins, $cabin.'.booked', 0));
            $totalCapacity += $capacity;

            if ($capacity === 0 || $fareMinor === 0) {
                $cabins[$cabin]['booked'] = 0;
                $cabins[$cabin]['revenue_minor'] = 0;
                continue;
            }

            $baseLoad = $this->baseCabinLoadFactor($flight->airline->business_model, $cabin);
            $referenceFareMinor = max(1, (int) ($referenceFares[$cabin.'_minor'] ?? $fareMinor));
            $sensitivity = match ($cabin) {
                'economy' => 1.25,
                'business' => 0.75,
                default => 0.55,
            };
            $priceFactor = pow($referenceFareMinor / max(1, $fareMinor), $sensitivity);
            $variationSeed = (int) sprintf('%u', crc32($flight->id.':'.$cabin));
            $variation = (($variationSeed % 21) - 10) / 100;
            $targetLoadFactor = min(
                0.98,
                max(0.05, ($baseLoad + $variation) * $demandIndex * $dayFactor * $priceFactor * $marketingMultiplier)
            );
            $targetBooked = min($capacity, (int) floor($capacity * $targetLoadFactor * $progress));
            $booked = min($capacity, max($alreadyBooked, $targetBooked));

            $cabins[$cabin]['capacity'] = $capacity;
            $cabins[$cabin]['fare_minor'] = $fareMinor;
            $cabins[$cabin]['booked'] = $booked;
            $cabins[$cabin]['revenue_minor'] = $booked * $fareMinor;
            $cabins[$cabin]['target_load_factor'] = round($targetLoadFactor, 4);
            $totalBooked += $booked;
        }

        $data['commercial'] = [
            'route_demand_index' => $demandIndex,
            'booking_window_days' => (int) config('simulation.booking_window_days', 14),
            'booking_progress' => round($progress, 4),
            'marketing' => $marketingSnapshot,
            'cabins' => $cabins,
        ];
        $data['load_factor'] = $totalCapacity > 0 ? round($totalBooked / $totalCapacity, 4) : 0;
        $data['seat_capacity'] = $totalCapacity;
        $data['demand_generated'] = $finalize || $progress >= 0.999;

        $changed = $totalBooked !== $previousPassengers
            || data_get($flight->operational_data, 'commercial.booking_progress') !== $data['commercial']['booking_progress'];

        $flight->forceFill([
            'passengers_booked' => $totalBooked,
            'operational_data' => $data,
        ])->save();

        return $changed;
    }

    private function bookingProgress(Flight $flight, Carbon $simulationNow): float
    {
        $windowMinutes = max(1440, ((int) config('simulation.booking_window_days', 14)) * 1440);
        $minutesToDeparture = $simulationNow->diffInMinutes($flight->scheduled_departure_at, false);

        if ($minutesToDeparture <= 0) {
            return 1.0;
        }

        if ($minutesToDeparture >= $windowMinutes) {
            return 0.08;
        }

        $elapsed = 1 - ($minutesToDeparture / $windowMinutes);

        return min(1.0, max(0.08, 0.08 + (0.92 * pow($elapsed, 1.25))));
    }

    private function baseCabinLoadFactor(string $businessModel, string $cabin): float
    {
        return match ($cabin) {
            'economy' => match ($businessModel) {
                'low_cost' => 0.90,
                'full_service' => 0.80,
                'regional' => 0.78,
                'cargo' => 0.25,
                default => 0.84,
            },
            'business' => match ($businessModel) {
                'low_cost' => 0.42,
                'full_service' => 0.69,
                'regional' => 0.52,
                'cargo' => 0.10,
                default => 0.60,
            },
            default => match ($businessModel) {
                'full_service' => 0.50,
                'cargo' => 0.05,
                default => 0.38,
            },
        };
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
                ->with(['route.origin', 'route.destination', 'aircraft.type', 'airline'])
                ->lockForUpdate()
                ->findOrFail($flight->id);

            if ($locked->status === 'completed') {
                return;
            }

            $this->updateBookings($locked, $departureAt, true);
            $locked->refresh();
            $locked->loadMissing(['route.destination', 'aircraft.type', 'airline']);

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

            $this->crewService->completeFlight($locked);
            $this->airportOperations->markFlightSlotsUsed($locked);
            $this->marketing->recordCompletedFlight($locked->fresh());
            $completed = true;
        });

        return $completed;
    }

    private function calculateEconomics(Flight $flight): array
    {
        $distanceKm = (float) ($flight->route?->distance_km ?? data_get($flight->operational_data, 'distance_km', 0));
        $passengers = (int) $flight->passengers_booked;
        $businessModel = $flight->airline?->business_model ?? 'hybrid';
        $cabinRevenue = [];
        $revenueMinor = 0;

        foreach (['economy', 'business', 'first'] as $cabin) {
            $booked = (int) data_get($flight->operational_data, 'commercial.cabins.'.$cabin.'.booked', 0);
            $fareMinor = (int) data_get($flight->operational_data, 'commercial.cabins.'.$cabin.'.fare_minor', 0);
            $cabinRevenue[$cabin] = $booked * $fareMinor;
            $revenueMinor += $cabinRevenue[$cabin];
        }

        if ($revenueMinor <= 0 && $passengers > 0) {
            $fareMultiplier = match ($businessModel) {
                'low_cost' => 0.82,
                'full_service' => 1.18,
                'regional' => 1.08,
                'cargo' => 0.55,
                default => 1.00,
            };
            $fallbackAverageFareMinor = (int) round((3500 + ($distanceKm * 11)) * $fareMultiplier);
            $revenueMinor = $passengers * $fallbackAverageFareMinor;
        }

        $averageFareMinor = $passengers > 0 ? (int) round($revenueMinor / $passengers) : 0;
        $blockHours = max(0.25, $flight->scheduled_departure_at->diffInMinutes($flight->scheduled_arrival_at) / 60);
        $burnPerHour = (float) data_get($flight->aircraft?->type?->technical_data, 'fuel_burn_l_per_hour', 2400);
        $fuelLiters = (int) round(($blockHours * $burnPerHour) + 250);
        $fuelCostMinor = $fuelLiters * (int) config('simulation.fuel_price_minor_per_liter', 88);
        $seats = max(1, (int) data_get($flight->aircraft?->configuration, 'seats', $flight->aircraft?->type?->typical_seats ?? 1));
        $operatingCostMinor = (int) round(150000 + ($seats * 350) + ($distanceKm * 60));
        $airportFees = $this->airportOperations->airportFeesForFlight($flight);
        $profitMinor = $revenueMinor - $fuelCostMinor - $operatingCostMinor - $airportFees['total_minor'];

        return [
            'passengers' => $passengers,
            'load_factor' => (float) data_get($flight->operational_data, 'load_factor', 0),
            'average_fare_minor' => $averageFareMinor,
            'cabin_revenue_minor' => $cabinRevenue,
            'revenue_minor' => $revenueMinor,
            'fuel_liters' => $fuelLiters,
            'fuel_cost_minor' => $fuelCostMinor,
            'operating_cost_minor' => $operatingCostMinor,
            'airport_fees_minor' => $airportFees['total_minor'],
            'airport_fees' => $airportFees,
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
        $airportExpense = $this->account($airline, 'AIRPORT_FEES', 'Flughafen- und Slotgebühren', 'expense');

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

        $netCashMinor = $economics['revenue_minor']
            - $economics['fuel_cost_minor']
            - $economics['operating_cost_minor']
            - ($economics['airport_fees_minor'] ?? 0);

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
            'memo' => 'Operative Flugkosten',
        ]);

        if (($economics['airport_fees_minor'] ?? 0) > 0) {
            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $airportExpense->id,
                'amount_minor' => $economics['airport_fees_minor'],
                'memo' => 'Airport- und Slotgebühren',
            ]);
        }
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
