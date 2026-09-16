<?php

namespace App\Services\Operations;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\World;
use App\Services\Commercial\RevenueManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightScheduleService
{
    public function __construct(private readonly RevenueManagementService $revenueManagement)
    {
    }

    public function minimumTurnaroundMinutes(Aircraft $aircraft): int
    {
        $aircraft->loadMissing('type');

        $configured = (int) data_get($aircraft->type?->technical_data, 'minimum_turnaround_minutes', 0);

        if ($configured > 0) {
            return $configured;
        }

        $seats = max(1, (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 1));

        return match (true) {
            $seats <= 120 => 35,
            $seats <= 160 => 40,
            $seats <= 200 => 45,
            $seats <= 260 => 55,
            default => 75,
        };
    }

    public function generateForWorld(World $world, Carbon $simulationNow): array
    {
        $summary = ['schedules' => 0, 'flights_created' => 0, 'rotations_skipped' => 0];

        FlightSchedule::query()
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (FlightSchedule $schedule) use ($simulationNow, &$summary): void {
                $summary['schedules']++;

                try {
                    $result = $this->generate($schedule, $simulationNow);
                    $summary['flights_created'] += $result['flights_created'];
                    $summary['rotations_skipped'] += $result['rotations_skipped'];
                } catch (\Throwable $exception) {
                    report($exception);
                    $summary['rotations_skipped']++;
                }
            });

        return $summary;
    }

    public function generate(FlightSchedule $schedule, Carbon $from): array
    {
        $schedule->loadMissing([
            'airline',
            'aircraft.type',
            'outboundRoute.origin',
            'outboundRoute.destination',
            'returnRoute.origin',
            'returnRoute.destination',
        ]);

        if ($schedule->status !== 'active') {
            return ['flights_created' => 0, 'rotations_skipped' => 0];
        }

        $this->assertRotationIntegrity($schedule);

        $horizonDays = max(7, min(90, (int) $schedule->generation_horizon_days));
        $until = $from->copy()->startOfDay()->addDays($horizonDays);

        if ($schedule->last_generated_on
            && $schedule->last_generated_on->copy()->startOfDay()->greaterThanOrEqualTo($until)) {
            return ['flights_created' => 0, 'rotations_skipped' => 0];
        }

        $start = $from->copy()->startOfDay();
        if ($schedule->last_generated_on) {
            $nextUngeneratedDay = $schedule->last_generated_on->copy()->startOfDay()->addDay();
            if ($nextUngeneratedDay->greaterThan($start)) {
                $start = $nextUngeneratedDay;
            }
        }

        $scheduleStart = $schedule->starts_on->copy()->startOfDay();
        if ($scheduleStart->greaterThan($start)) {
            $start = $scheduleStart;
        }

        $days = array_values(array_unique(array_map('intval', $schedule->days_of_week ?? [])));
        $flightsCreated = 0;
        $rotationsSkipped = 0;

        for ($date = $start->copy(); $date->lessThanOrEqualTo($until); $date->addDay()) {
            if (! in_array($date->dayOfWeekIso, $days, true)) {
                continue;
            }

            $departure = Carbon::createFromFormat(
                'Y-m-d H:i',
                $date->format('Y-m-d').' '.$schedule->departure_time,
                config('app.timezone')
            );

            if ($departure->lessThanOrEqualTo($from)) {
                continue;
            }

            $result = $this->generateRotation($schedule, $departure);
            $flightsCreated += $result['flights_created'];
            $rotationsSkipped += $result['skipped'] ? 1 : 0;
        }

        $schedule->forceFill(['last_generated_on' => $until->toDateString()])->save();

        return [
            'flights_created' => $flightsCreated,
            'rotations_skipped' => $rotationsSkipped,
        ];
    }

    public function commercialSnapshot(Aircraft $aircraft, AirlineRoute $route, Airline $airline): array
    {
        $aircraft->loadMissing('type');
        $totalSeats = max(1, (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 1));
        $configuredCabins = data_get($aircraft->configuration, 'cabins');
        $cabins = is_array($configuredCabins) && array_sum(array_map('intval', $configuredCabins)) > 0
            ? [
                'economy' => max(0, (int) ($configuredCabins['economy'] ?? 0)),
                'business' => max(0, (int) ($configuredCabins['business'] ?? 0)),
                'first' => max(0, (int) ($configuredCabins['first'] ?? 0)),
            ]
            : $this->revenueManagement->cabinLayout($totalSeats, $airline->business_model);

        $fares = $this->revenueManagement->routeFares($route, $airline->business_model);

        return [
            'planned_block_minutes' => $route->planned_block_minutes,
            'distance_km' => (float) $route->distance_km,
            'commercial' => [
                'route_demand_index' => (float) data_get($route->settings, 'demand_index', 1.0),
                'booking_window_days' => (int) config('simulation.booking_window_days', 14),
                'cabins' => [
                    'economy' => [
                        'capacity' => $cabins['economy'],
                        'fare_minor' => $fares['economy_minor'],
                        'booked' => 0,
                    ],
                    'business' => [
                        'capacity' => $cabins['business'],
                        'fare_minor' => $fares['business_minor'],
                        'booked' => 0,
                    ],
                    'first' => [
                        'capacity' => $cabins['first'],
                        'fare_minor' => $fares['first_minor'],
                        'booked' => 0,
                    ],
                ],
            ],
        ];
    }

    private function generateRotation(FlightSchedule $schedule, Carbon $outboundDeparture): array
    {
        $outboundRoute = $schedule->outboundRoute;
        $returnRoute = $schedule->returnRoute;
        $aircraft = $schedule->aircraft;
        $airline = $schedule->airline;
        $minimumTurnaround = $this->minimumTurnaroundMinutes($aircraft);
        $turnaround = max($minimumTurnaround, (int) $schedule->turnaround_minutes);
        $outboundArrival = $outboundDeparture->copy()->addMinutes($outboundRoute->planned_block_minutes);
        $returnDeparture = $outboundArrival->copy()->addMinutes($turnaround);
        $returnArrival = $returnDeparture->copy()->addMinutes($returnRoute->planned_block_minutes);

        $existing = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->whereBetween('scheduled_departure_at', [
                $outboundDeparture->copy()->subMinute(),
                $returnDeparture->copy()->addMinute(),
            ])
            ->get();

        if ($existing->count() >= 2) {
            return ['flights_created' => 0, 'skipped' => false];
        }

        $excludedIds = $existing->pluck('id')->all();
        $flightNumberConflict = Flight::query()
            ->where('world_id', $schedule->world_id)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->where(function ($query) use ($schedule, $outboundDeparture, $returnDeparture): void {
                $query->where(function ($query) use ($schedule, $outboundDeparture): void {
                    $query->where('flight_number', $schedule->outbound_flight_number)
                        ->where('scheduled_departure_at', $outboundDeparture);
                })->orWhere(function ($query) use ($schedule, $returnDeparture): void {
                    $query->where('flight_number', $schedule->return_flight_number)
                        ->where('scheduled_departure_at', $returnDeparture);
                });
            })
            ->exists();

        if ($flightNumberConflict) {
            return ['flights_created' => 0, 'skipped' => true];
        }

        if (! $this->rotationWindowIsAvailable(
            $schedule,
            $outboundDeparture,
            $returnArrival,
            $minimumTurnaround,
            $excludedIds
        )) {
            return ['flights_created' => 0, 'skipped' => true];
        }

        $created = 0;

        DB::transaction(function () use (
            $schedule,
            $outboundRoute,
            $returnRoute,
            $aircraft,
            $airline,
            $outboundDeparture,
            $outboundArrival,
            $returnDeparture,
            $returnArrival,
            $existing,
            &$created
        ): void {
            $outboundExists = $existing->contains(fn (Flight $flight): bool => $flight->route_id === $outboundRoute->id);
            $returnExists = $existing->contains(fn (Flight $flight): bool => $flight->route_id === $returnRoute->id);

            if (! $outboundExists) {
                Flight::create([
                    'world_id' => $schedule->world_id,
                    'airline_id' => $schedule->airline_id,
                    'route_id' => $outboundRoute->id,
                    'aircraft_id' => $aircraft->id,
                    'flight_schedule_id' => $schedule->id,
                    'flight_number' => $schedule->outbound_flight_number,
                    'scheduled_departure_at' => $outboundDeparture,
                    'scheduled_arrival_at' => $outboundArrival,
                    'status' => 'scheduled',
                    'delay_minutes' => 0,
                    'passengers_booked' => 0,
                    'cargo_kg_booked' => 0,
                    'operational_data' => array_replace_recursive(
                        $this->commercialSnapshot($aircraft, $outboundRoute, $airline),
                        [
                            'rotation' => [
                                'schedule_id' => $schedule->id,
                                'leg' => 'outbound',
                            ],
                        ]
                    ),
                ]);
                $created++;
            }

            if (! $returnExists) {
                Flight::create([
                    'world_id' => $schedule->world_id,
                    'airline_id' => $schedule->airline_id,
                    'route_id' => $returnRoute->id,
                    'aircraft_id' => $aircraft->id,
                    'flight_schedule_id' => $schedule->id,
                    'flight_number' => $schedule->return_flight_number,
                    'scheduled_departure_at' => $returnDeparture,
                    'scheduled_arrival_at' => $returnArrival,
                    'status' => 'scheduled',
                    'delay_minutes' => 0,
                    'passengers_booked' => 0,
                    'cargo_kg_booked' => 0,
                    'operational_data' => array_replace_recursive(
                        $this->commercialSnapshot($aircraft, $returnRoute, $airline),
                        [
                            'rotation' => [
                                'schedule_id' => $schedule->id,
                                'leg' => 'return',
                                'turnaround_minutes' => (int) $schedule->turnaround_minutes,
                            ],
                        ]
                    ),
                ]);
                $created++;
            }
        });

        return ['flights_created' => $created, 'skipped' => false];
    }

    private function rotationWindowIsAvailable(
        FlightSchedule $schedule,
        Carbon $departure,
        Carbon $returnArrival,
        int $minimumTurnaround,
        array $excludedFlightIds
    ): bool {
        $conflict = Flight::query()
            ->where('aircraft_id', $schedule->aircraft_id)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludedFlightIds !== [], fn ($query) => $query->whereNotIn('id', $excludedFlightIds))
            ->where('scheduled_departure_at', '<', $returnArrival->copy()->addMinutes($minimumTurnaround))
            ->where('scheduled_arrival_at', '>', $departure->copy()->subMinutes($minimumTurnaround))
            ->exists();

        if ($conflict) {
            return false;
        }

        $previous = Flight::query()
            ->with('route')
            ->where('aircraft_id', $schedule->aircraft_id)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludedFlightIds !== [], fn ($query) => $query->whereNotIn('id', $excludedFlightIds))
            ->where('scheduled_arrival_at', '<=', $departure)
            ->orderByDesc('scheduled_arrival_at')
            ->first();

        if ($previous && $previous->route?->destination_airport_id !== $schedule->outboundRoute->origin_airport_id) {
            return false;
        }

        if (! $previous && $schedule->aircraft->current_airport_id
            && $schedule->aircraft->current_airport_id !== $schedule->outboundRoute->origin_airport_id) {
            return false;
        }

        $next = Flight::query()
            ->with('route')
            ->where('aircraft_id', $schedule->aircraft_id)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludedFlightIds !== [], fn ($query) => $query->whereNotIn('id', $excludedFlightIds))
            ->where('scheduled_departure_at', '>=', $returnArrival)
            ->orderBy('scheduled_departure_at')
            ->first();

        if ($next && $next->route?->origin_airport_id !== $schedule->outboundRoute->origin_airport_id) {
            return false;
        }

        return true;
    }

    private function assertRotationIntegrity(FlightSchedule $schedule): void
    {
        $outbound = $schedule->outboundRoute;
        $return = $schedule->returnRoute;
        $aircraft = $schedule->aircraft;

        if (! $outbound || ! $return || ! $aircraft || ! $schedule->airline) {
            throw new \RuntimeException('Der Flugplan enthält unvollständige Beziehungen.');
        }

        if ($outbound->destination_airport_id !== $return->origin_airport_id
            || $outbound->origin_airport_id !== $return->destination_airport_id) {
            throw new \RuntimeException('Hin- und Rückroute bilden keinen geschlossenen Umlauf.');
        }

        foreach ([$outbound, $return] as $route) {
            if ($route->world_id !== $schedule->world_id || $route->airline_id !== $schedule->airline_id) {
                throw new \RuntimeException('Eine Route gehört nicht zum Flugplan-Kontext.');
            }

            if ($aircraft->type?->range_km && (float) $route->distance_km > (float) $aircraft->type->range_km) {
                throw new \RuntimeException('Die Reichweite des Flugzeugs reicht für den Umlauf nicht aus.');
            }
        }
    }
}
