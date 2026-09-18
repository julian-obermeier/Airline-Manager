<?php

namespace App\Services\Operations;

use App\Models\Airline;
use App\Models\AirlineAirportStation;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\AirportSlotReservation;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AirportOperationsService
{
    public function ensureNetworkStations(Airline $airline): int
    {
        $airline->loadMissing(['homeAirport', 'routes.origin', 'routes.destination']);
        $created = 0;

        if ($airline->homeAirport) {
            $station = $this->ensureStation($airline, $airline->homeAirport, 'base');
            if ($station->wasRecentlyCreated) {
                $created++;
            }
        }

        foreach ($airline->routes as $route) {
            foreach ([$route->origin, $route->destination] as $airport) {
                if (! $airport) {
                    continue;
                }

                $type = $airport->id === $airline->home_airport_id ? 'base' : 'outstation';
                $station = $this->ensureStation($airline, $airport, $type);
                if ($station->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }

    public function ensureStation(Airline $airline, Airport $airport, ?string $stationType = null): AirlineAirportStation
    {
        $stationType ??= $airport->id === $airline->home_airport_id ? 'base' : 'outstation';
        $openedAt = $airline->world?->simulated_at ?? now();

        $station = AirlineAirportStation::query()->firstOrCreate(
            [
                'airline_id' => $airline->id,
                'airport_id' => $airport->id,
            ],
            [
                'world_id' => $airline->world_id,
                'station_type' => $stationType,
                'status' => 'active',
                'opened_at' => $openedAt,
                'monthly_cost_minor' => $this->stationMonthlyCostMinor($airport, $stationType),
                'currency' => $airline->base_currency,
                'metadata' => [
                    'source' => 'network_auto_open',
                    'slot_capacity_per_bucket' => $this->slotCapacity($airport),
                ],
            ]
        );

        if (! $station->wasRecentlyCreated) {
            $changes = [];

            if ($stationType === 'base' && $station->station_type !== 'base') {
                $changes['station_type'] = 'base';
                $changes['monthly_cost_minor'] = $this->stationMonthlyCostMinor($airport, 'base');
            }

            if ($station->status !== 'active') {
                $changes['status'] = 'active';
                $changes['closed_at'] = null;
            }

            if ($changes !== []) {
                $station->forceFill($changes)->save();
            }
        }

        return $station;
    }

    public function canReserveLeg(World $world, AirlineRoute $route, Carbon $departure, Carbon $arrival, ?string $flightId = null): bool
    {
        $route->loadMissing(['origin', 'destination']);

        if (! $route->origin || ! $route->destination) {
            return false;
        }

        return $this->slotAvailable($world, $route->origin, $departure, $flightId)
            && $this->slotAvailable($world, $route->destination, $arrival, $flightId);
    }

    public function assertSlotsAvailable(World $world, AirlineRoute $route, Carbon $departure, Carbon $arrival, ?string $flightId = null): void
    {
        $route->loadMissing(['origin', 'destination']);

        if (! $route->origin || ! $route->destination) {
            throw ValidationException::withMessages([
                'route_id' => 'Die Route enthält ungültige Flughafendaten.',
            ]);
        }

        foreach ([
            ['airport' => $route->origin, 'at' => $departure, 'label' => 'Abflug'],
            ['airport' => $route->destination, 'at' => $arrival, 'label' => 'Ankunft'],
        ] as $movement) {
            if (! $this->slotAvailable($world, $movement['airport'], $movement['at'], $flightId)) {
                throw ValidationException::withMessages([
                    'scheduled_departure_at' => $movement['label'].'slot in '.$movement['airport']->iata_code.' ist im gewählten Zeitfenster ausgelastet.',
                ]);
            }
        }
    }

    public function reserveFlight(Flight $flight): array
    {
        $flight->loadMissing(['world', 'airline.world', 'route.origin', 'route.destination']);

        if (! $flight->world || ! $flight->airline || ! $flight->route || ! $flight->route->origin || ! $flight->route->destination) {
            return ['reserved' => false, 'created' => 0];
        }

        $this->ensureStation(
            $flight->airline,
            $flight->route->origin,
            $flight->route->origin_airport_id === $flight->airline->home_airport_id ? 'base' : 'outstation'
        );
        $this->ensureStation(
            $flight->airline,
            $flight->route->destination,
            $flight->route->destination_airport_id === $flight->airline->home_airport_id ? 'base' : 'outstation'
        );

        $this->assertSlotsAvailable(
            $flight->world,
            $flight->route,
            $flight->scheduled_departure_at,
            $flight->scheduled_arrival_at,
            $flight->id
        );

        $created = 0;

        foreach ([
            ['type' => 'departure', 'airport' => $flight->route->origin, 'at' => $flight->scheduled_departure_at],
            ['type' => 'arrival', 'airport' => $flight->route->destination, 'at' => $flight->scheduled_arrival_at],
        ] as $movement) {
            $slot = AirportSlotReservation::query()->firstOrCreate(
                [
                    'flight_id' => $flight->id,
                    'movement_type' => $movement['type'],
                ],
                [
                    'world_id' => $flight->world_id,
                    'airline_id' => $flight->airline_id,
                    'airport_id' => $movement['airport']->id,
                    'scheduled_at' => $movement['at'],
                    'slot_key' => $this->slotKey($movement['at']),
                    'status' => 'reserved',
                    'fee_minor' => (int) config('airport_operations.slot_fee_minor', 20000),
                    'currency' => $flight->airline->base_currency,
                    'metadata' => [
                        'bucket_minutes' => $this->bucketMinutes(),
                        'capacity' => $this->slotCapacity($movement['airport']),
                    ],
                ]
            );

            if ($slot->wasRecentlyCreated) {
                $created++;
            }
        }

        return ['reserved' => true, 'created' => $created];
    }

    public function reserveMissingForAirline(Airline $airline, ?Carbon $from = null): array
    {
        $from ??= $airline->world?->simulated_at ?? now();
        $summary = ['checked' => 0, 'reserved' => 0, 'unavailable' => 0];

        $this->ensureNetworkStations($airline);

        Flight::query()
            ->with(['world', 'airline.world', 'route.origin', 'route.destination'])
            ->where('airline_id', $airline->id)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('scheduled_departure_at', '>=', $from)
            ->orderBy('scheduled_departure_at')
            ->each(function (Flight $flight) use (&$summary): void {
                $summary['checked']++;

                try {
                    $result = $this->reserveFlight($flight);
                    $summary['reserved'] += $result['created'];
                } catch (ValidationException) {
                    $summary['unavailable']++;
                }
            });

        return $summary;
    }

    public function markFlightSlotsUsed(Flight $flight): void
    {
        AirportSlotReservation::query()
            ->where('flight_id', $flight->id)
            ->where('status', 'reserved')
            ->update(['status' => 'used']);
    }

    public function airportFeesForFlight(Flight $flight): array
    {
        $flight->loadMissing(['route.origin', 'route.destination', 'aircraft.type']);
        $seats = max(1, (int) data_get(
            $flight->aircraft?->configuration,
            'seats',
            $flight->aircraft?->type?->typical_seats ?? 1
        ));
        $passengers = max(0, (int) $flight->passengers_booked);

        $movementBase = (int) config('airport_operations.base_movement_fee_minor', 50000);
        $seatFee = $seats * (int) config('airport_operations.per_seat_movement_fee_minor', 250);
        $passengerService = $passengers * (int) config('airport_operations.per_passenger_service_fee_minor', 450);

        $departureMinor = $movementBase + $seatFee + $passengerService;
        $arrivalMinor = $movementBase + $seatFee + $passengerService;
        $slotFeesMinor = (int) AirportSlotReservation::query()
            ->where('flight_id', $flight->id)
            ->sum('fee_minor');

        return [
            'departure_fee_minor' => $departureMinor,
            'arrival_fee_minor' => $arrivalMinor,
            'slot_fees_minor' => $slotFeesMinor,
            'total_minor' => $departureMinor + $arrivalMinor + $slotFeesMinor,
        ];
    }

    public function processStationFees(World $world, Carbon $simulationNow): array
    {
        $summary = ['station_fee_runs' => 0, 'station_cost_minor' => 0];
        $lastPayableMonth = $simulationNow->copy()->startOfMonth()->subMonth();

        AirlineAirportStation::query()
            ->with('airline')
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (AirlineAirportStation $station) use ($world, $lastPayableMonth, &$summary): void {
                if (! $station->airline) {
                    return;
                }

                $cursor = $station->opened_at->copy()->startOfMonth();

                while ($cursor->lessThanOrEqualTo($lastPayableMonth)) {
                    $period = $cursor->format('Y-m');
                    $key = 'airport-station:'.$station->id.':'.$period;

                    if (LedgerTransaction::query()
                        ->where('world_id', $world->id)
                        ->where('idempotency_key', $key)
                        ->exists()) {
                        $cursor->addMonthNoOverflow();
                        continue;
                    }

                    $amountMinor = (int) $station->monthly_cost_minor;

                    if ($amountMinor > 0) {
                        DB::transaction(function () use ($world, $station, $period, $key, $amountMinor, $cursor): void {
                            $airline = $station->airline;
                            $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
                            $expense = $this->account($airline, 'AIRPORT_STATION_EXPENSE', 'Stationskosten', 'expense');

                            $transaction = LedgerTransaction::create([
                                'world_id' => $world->id,
                                'airline_id' => $airline->id,
                                'idempotency_key' => $key,
                                'reference_type' => 'airport_station_fee',
                                'reference_id' => $station->id,
                                'description' => 'Stationskosten '.$station->airport_id.' · '.$period,
                                'occurred_at' => $cursor->copy()->endOfMonth(),
                                'posted_at' => now(),
                                'metadata' => [
                                    'period' => $period,
                                    'station_id' => $station->id,
                                    'airport_id' => $station->airport_id,
                                    'station_type' => $station->station_type,
                                    'amount_minor' => $amountMinor,
                                ],
                            ]);

                            LedgerEntry::create([
                                'ledger_transaction_id' => $transaction->id,
                                'ledger_account_id' => $expense->id,
                                'amount_minor' => $amountMinor,
                                'memo' => 'Station '.$period,
                            ]);

                            LedgerEntry::create([
                                'ledger_transaction_id' => $transaction->id,
                                'ledger_account_id' => $cash->id,
                                'amount_minor' => -$amountMinor,
                                'memo' => 'Stationskosten '.$period,
                            ]);
                        });

                        $summary['station_fee_runs']++;
                        $summary['station_cost_minor'] += $amountMinor;
                    }

                    $cursor->addMonthNoOverflow();
                }
            });

        return $summary;
    }

    public function slotCapacity(Airport $airport): int
    {
        return max(
            1,
            (int) data_get(
                $airport->metadata,
                'slot_capacity_15min',
                config('airport_operations.default_slot_capacity_per_bucket', 12)
            )
        );
    }

    public function stationMonthlyCostMinor(Airport $airport, string $stationType): int
    {
        $base = (int) config('airport_operations.station_monthly_minor.'.$stationType, 12000000);
        $capacity = $this->slotCapacity($airport);
        $factor = 0.75 + min(1.0, $capacity / 20);

        return (int) round($base * $factor);
    }

    public function slotKey(Carbon $at): string
    {
        $bucket = $this->bucketMinutes();
        $rounded = $at->copy()
            ->minute((int) (floor($at->minute / $bucket) * $bucket))
            ->second(0);

        return $rounded->format('YmdHi');
    }

    private function slotAvailable(World $world, Airport $airport, Carbon $at, ?string $flightId = null): bool
    {
        $slotKey = $this->slotKey($at);

        $count = AirportSlotReservation::query()
            ->where('world_id', $world->id)
            ->where('airport_id', $airport->id)
            ->where('slot_key', $slotKey)
            ->whereIn('status', ['reserved', 'used'])
            ->when($flightId, fn ($query) => $query->where('flight_id', '!=', $flightId))
            ->whereHas('flight', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->count();

        return $count < $this->slotCapacity($airport);
    }

    private function bucketMinutes(): int
    {
        return max(5, min(60, (int) config('airport_operations.slot_bucket_minutes', 15)));
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
