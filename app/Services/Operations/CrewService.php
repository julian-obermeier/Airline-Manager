<?php

namespace App\Services\Operations;

use App\Models\Airline;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\FlightCrewAssignment;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CrewService
{
    public function requirementsForFlight(Flight $flight): array
    {
        $flight->loadMissing(['aircraft.type', 'airline']);

        $seats = max(
            1,
            (int) data_get(
                $flight->aircraft?->configuration,
                'seats',
                $flight->aircraft?->type?->typical_seats ?? 1
            )
        );

        $cabinCrew = $flight->airline?->business_model === 'cargo'
            ? 0
            : max(1, (int) ceil($seats / max(1, (int) config('crew.cabin_seats_per_member', 50))));

        return [
            'captain' => 1,
            'first_officer' => 1,
            'cabin_crew' => $cabinCrew,
            'total' => 2 + $cabinCrew,
        ];
    }

    public function assignCrew(Flight $flight): array
    {
        $flight->loadMissing([
            'airline',
            'aircraft.type',
            'route.origin',
            'route.destination',
            'crewAssignments.crewMember.qualifications',
        ]);

        if (in_array($flight->status, ['cancelled', 'completed'], true)
            || ! $flight->airline
            || ! $flight->aircraft
            || ! $flight->route) {
            return $this->staffingSnapshot($flight);
        }

        $this->releaseInvalidAssignments($flight);
        $flight->unsetRelation('crewAssignments');
        $requirements = $this->requirementsForFlight($flight);

        foreach (['captain', 'first_officer', 'cabin_crew'] as $role) {
            $assigned = FlightCrewAssignment::query()
                ->where('flight_id', $flight->id)
                ->where('duty_role', $role)
                ->where('status', 'assigned')
                ->count();

            $needed = max(0, (int) $requirements[$role] - $assigned);

            if ($needed === 0) {
                continue;
            }

            $candidates = CrewMember::query()
                ->with(['qualifications', 'currentAirport'])
                ->where('world_id', $flight->world_id)
                ->where('airline_id', $flight->airline_id)
                ->where('role', $role)
                ->where('status', 'active')
                ->where('hired_at', '<=', $flight->scheduled_departure_at)
                ->where(function ($query) use ($flight): void {
                    $query->whereNull('terminated_at')
                        ->orWhere('terminated_at', '>', $flight->scheduled_departure_at);
                })
                ->orderBy('employee_number')
                ->get()
                ->sortBy(fn (CrewMember $member): int => $member->current_airport_id === $flight->route->origin_airport_id ? 0 : 1);

            foreach ($candidates as $member) {
                if ($needed <= 0) {
                    break;
                }

                if (FlightCrewAssignment::query()
                    ->where('flight_id', $flight->id)
                    ->where('crew_member_id', $member->id)
                    ->whereIn('status', ['assigned', 'completed'])
                    ->exists()) {
                    continue;
                }

                if (! $this->isAvailableForFlight($member, $flight)) {
                    continue;
                }

                FlightCrewAssignment::create([
                    'flight_id' => $flight->id,
                    'crew_member_id' => $member->id,
                    'duty_role' => $role,
                    'duty_start_at' => $flight->scheduled_departure_at,
                    'duty_end_at' => $flight->scheduled_arrival_at,
                    'assigned_at' => now(),
                    'status' => 'assigned',
                    'metadata' => [
                        'assignment_source' => 'automatic',
                        'simulation_rule' => 'crew_v1',
                    ],
                ]);

                $needed--;
            }
        }

        $flight->unsetRelation('crewAssignments');

        return $this->staffingSnapshot($flight);
    }

    public function staffingSnapshot(Flight $flight): array
    {
        $flight->loadMissing(['aircraft.type', 'airline', 'crewAssignments.crewMember']);
        $requirements = $this->requirementsForFlight($flight);

        $counts = [
            'captain' => 0,
            'first_officer' => 0,
            'cabin_crew' => 0,
        ];

        foreach ($flight->crewAssignments as $assignment) {
            if (! in_array($assignment->status, ['assigned', 'completed'], true)) {
                continue;
            }

            if (array_key_exists($assignment->duty_role, $counts)) {
                $counts[$assignment->duty_role]++;
            }
        }

        $missing = [
            'captain' => max(0, $requirements['captain'] - $counts['captain']),
            'first_officer' => max(0, $requirements['first_officer'] - $counts['first_officer']),
            'cabin_crew' => max(0, $requirements['cabin_crew'] - $counts['cabin_crew']),
        ];
        $missing['total'] = array_sum($missing);

        return [
            'requirements' => $requirements,
            'assigned' => $counts + ['total' => array_sum($counts)],
            'missing' => $missing,
            'complete' => $missing['total'] === 0,
        ];
    }

    public function readyForDeparture(Flight $flight): bool
    {
        $snapshot = $this->assignCrew($flight);

        return $snapshot['complete'];
    }

    public function cancelForShortage(Flight $flight): void
    {
        $snapshot = $this->staffingSnapshot($flight);
        $data = $flight->operational_data ?? [];
        $data['operations'] = array_merge($data['operations'] ?? [], [
            'cancelled_at' => now()->toIso8601String(),
            'cancellation_code' => 'crew_shortage',
            'cancellation_reason' => 'Der Flug konnte nicht mit der erforderlichen qualifizierten Crew besetzt werden.',
            'crew_missing' => $snapshot['missing'],
        ]);

        $flight->forceFill([
            'status' => 'cancelled',
            'operational_data' => $data,
        ])->save();

        FlightCrewAssignment::query()
            ->where('flight_id', $flight->id)
            ->where('status', 'assigned')
            ->update(['status' => 'released']);
    }

    public function completeFlight(Flight $flight): void
    {
        $flight->loadMissing(['route.destination', 'crewAssignments.crewMember']);

        if (! $flight->route) {
            return;
        }

        foreach ($flight->crewAssignments as $assignment) {
            if (! in_array($assignment->status, ['assigned', 'completed'], true) || ! $assignment->crewMember) {
                continue;
            }

            $assignment->forceFill([
                'status' => 'completed',
                'duty_end_at' => $flight->actual_arrival_at ?? $flight->scheduled_arrival_at,
            ])->save();

            $assignment->crewMember->forceFill([
                'current_airport_id' => $flight->route->destination_airport_id,
            ])->save();
        }
    }

    public function assignUpcomingForAirline(Airline $airline, ?Carbon $from = null): array
    {
        $from ??= $airline->world?->simulated_at ?? now();
        $until = $from->copy()->addDays(max(7, (int) config('crew.planning_horizon_days', 90)));
        $checked = 0;
        $completed = 0;

        Flight::query()
            ->where('airline_id', $airline->id)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->whereBetween('scheduled_departure_at', [$from, $until])
            ->orderBy('scheduled_departure_at')
            ->each(function (Flight $flight) use (&$checked, &$completed): void {
                $checked++;
                if ($this->assignCrew($flight)['complete']) {
                    $completed++;
                }
            });

        return ['checked' => $checked, 'fully_staffed' => $completed];
    }

    public function processPayroll(World $world, Carbon $simulationNow): array
    {
        $summary = ['payroll_runs' => 0, 'salary_minor' => 0];

        if ($simulationNow->copy()->startOfMonth()->lessThanOrEqualTo($world->starts_at?->copy()->startOfMonth() ?? $simulationNow->copy()->startOfMonth())) {
            return $summary;
        }

        Airline::query()
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (Airline $airline) use ($world, $simulationNow, &$summary): void {
                $firstCrew = CrewMember::query()
                    ->where('airline_id', $airline->id)
                    ->orderBy('hired_at')
                    ->first();

                if (! $firstCrew) {
                    return;
                }

                $lastPayableMonth = $simulationNow->copy()->startOfMonth()->subMonth();
                $cursor = $firstCrew->hired_at->copy()->startOfMonth();

                while ($cursor->lessThanOrEqualTo($lastPayableMonth)) {
                    $period = $cursor->format('Y-m');
                    $key = 'crew-payroll:'.$airline->id.':'.$period;

                    if (LedgerTransaction::query()
                        ->where('world_id', $world->id)
                        ->where('idempotency_key', $key)
                        ->exists()) {
                        $cursor->addMonthNoOverflow();
                        continue;
                    }

                    $periodStart = $cursor->copy()->startOfMonth();
                    $periodEnd = $cursor->copy()->endOfMonth();

                    $members = CrewMember::query()
                        ->where('airline_id', $airline->id)
                        ->where('hired_at', '<=', $periodEnd)
                        ->where(function ($query) use ($periodStart): void {
                            $query->whereNull('terminated_at')
                                ->orWhere('terminated_at', '>=', $periodStart);
                        })
                        ->get();

                    $items = [];
                    $totalMinor = 0;

                    foreach ($members as $member) {
                        $employmentStart = $member->hired_at->copy()->greaterThan($periodStart)
                            ? $member->hired_at->copy()->startOfDay()
                            : $periodStart->copy();
                        $employmentEnd = $member->terminated_at && $member->terminated_at->copy()->lessThan($periodEnd)
                            ? $member->terminated_at->copy()->endOfDay()
                            : $periodEnd->copy();

                        if ($employmentEnd->lessThan($employmentStart)) {
                            continue;
                        }

                        $days = $employmentStart->copy()->startOfDay()->diffInDays($employmentEnd->copy()->startOfDay()) + 1;
                        $amountMinor = (int) round(
                            ((int) $member->monthly_salary_minor) * ($days / $periodStart->daysInMonth)
                        );

                        if ($amountMinor <= 0) {
                            continue;
                        }

                        $items[] = [
                            'crew_member_id' => $member->id,
                            'employee_number' => $member->employee_number,
                            'name' => $member->full_name,
                            'role' => $member->role,
                            'days' => $days,
                            'amount_minor' => $amountMinor,
                        ];
                        $totalMinor += $amountMinor;
                    }

                    if ($totalMinor > 0) {
                        DB::transaction(function () use ($world, $airline, $key, $period, $periodEnd, $items, $totalMinor): void {
                            $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
                            $expense = $this->account($airline, 'PERSONNEL_EXPENSE', 'Personalkosten', 'expense');

                            $transaction = LedgerTransaction::create([
                                'world_id' => $world->id,
                                'airline_id' => $airline->id,
                                'idempotency_key' => $key,
                                'reference_type' => 'crew_payroll',
                                'reference_id' => $period,
                                'description' => 'Personalabrechnung '.$period,
                                'occurred_at' => $periodEnd,
                                'posted_at' => now(),
                                'metadata' => [
                                    'period' => $period,
                                    'crew' => $items,
                                    'total_minor' => $totalMinor,
                                ],
                            ]);

                            LedgerEntry::create([
                                'ledger_transaction_id' => $transaction->id,
                                'ledger_account_id' => $expense->id,
                                'amount_minor' => $totalMinor,
                                'memo' => 'Gehälter Crew '.$period,
                            ]);
                            LedgerEntry::create([
                                'ledger_transaction_id' => $transaction->id,
                                'ledger_account_id' => $cash->id,
                                'amount_minor' => -$totalMinor,
                                'memo' => 'Auszahlung Gehälter '.$period,
                            ]);
                        });

                        $summary['payroll_runs']++;
                        $summary['salary_minor'] += $totalMinor;
                    }

                    $cursor->addMonthNoOverflow();
                }
            });

        return $summary;
    }

    private function releaseInvalidAssignments(Flight $flight): void
    {
        $assignments = FlightCrewAssignment::query()
            ->with(['crewMember.qualifications'])
            ->where('flight_id', $flight->id)
            ->where('status', 'assigned')
            ->get();

        foreach ($assignments as $assignment) {
            $member = $assignment->crewMember;

            if (! $member
                || $member->status !== 'active'
                || $member->role !== $assignment->duty_role
                || ! $this->qualificationValid($member, $flight)
                || ! $this->isAvailableForFlight($member, $flight, $flight->id)) {
                $assignment->forceFill(['status' => 'released'])->save();
            }
        }
    }

    private function isAvailableForFlight(CrewMember $member, Flight $flight, ?string $excludeFlightId = null): bool
    {
        $departure = $flight->scheduled_departure_at->copy()->addMinutes((int) $flight->delay_minutes);
        $arrival = $flight->scheduled_arrival_at->copy()->addMinutes((int) $flight->delay_minutes);
        $flight->loadMissing(['route.origin', 'route.destination', 'aircraft.type']);

        if (! $this->qualificationValid($member, $flight)) {
            return false;
        }

        $conflict = FlightCrewAssignment::query()
            ->where('crew_member_id', $member->id)
            ->where('status', 'assigned')
            ->when($excludeFlightId, fn ($query) => $query->where('flight_id', '!=', $excludeFlightId))
            ->where('duty_start_at', '<', $arrival)
            ->where('duty_end_at', '>', $departure)
            ->exists();

        if ($conflict) {
            return false;
        }

        $previous = FlightCrewAssignment::query()
            ->with(['flight.route.destination'])
            ->where('crew_member_id', $member->id)
            ->whereIn('status', ['assigned', 'completed'])
            ->when($excludeFlightId, fn ($query) => $query->where('flight_id', '!=', $excludeFlightId))
            ->where('duty_end_at', '<=', $departure)
            ->orderByDesc('duty_end_at')
            ->first();

        $expectedOriginId = $previous?->flight?->route?->destination_airport_id ?? $member->current_airport_id;

        if (! $expectedOriginId || $expectedOriginId !== $flight->route?->origin_airport_id) {
            return false;
        }

        if ($previous) {
            $gap = $previous->duty_end_at->diffInMinutes($departure, false);
            $continuousGap = max(0, (int) config('crew.continuous_duty_gap_minutes', 240));

            if ($gap > $continuousGap && $gap < (int) $member->min_rest_minutes) {
                return false;
            }
        }

        $next = FlightCrewAssignment::query()
            ->with(['flight.route.origin'])
            ->where('crew_member_id', $member->id)
            ->where('status', 'assigned')
            ->when($excludeFlightId, fn ($query) => $query->where('flight_id', '!=', $excludeFlightId))
            ->where('duty_start_at', '>=', $arrival)
            ->orderBy('duty_start_at')
            ->first();

        if ($next) {
            if ($next->flight?->route?->origin_airport_id
                && $next->flight->route->origin_airport_id !== $flight->route?->destination_airport_id) {
                return false;
            }

            $gap = $arrival->diffInMinutes($next->duty_start_at, false);
            $continuousGap = max(0, (int) config('crew.continuous_duty_gap_minutes', 240));

            if ($gap > $continuousGap && $gap < (int) $member->min_rest_minutes) {
                return false;
            }
        }

        $dayStart = $departure->copy()->startOfDay();
        $dayEnd = $departure->copy()->endOfDay();

        $existingDutyMinutes = FlightCrewAssignment::query()
            ->where('crew_member_id', $member->id)
            ->whereIn('status', ['assigned', 'completed'])
            ->when($excludeFlightId, fn ($query) => $query->where('flight_id', '!=', $excludeFlightId))
            ->whereBetween('duty_start_at', [$dayStart, $dayEnd])
            ->get()
            ->sum(fn (FlightCrewAssignment $assignment): int =>
                $assignment->duty_start_at->diffInMinutes($assignment->duty_end_at) + 45
            );

        $candidateMinutes = $departure->diffInMinutes($arrival) + 45;

        return ($existingDutyMinutes + $candidateMinutes) <= (int) $member->max_duty_minutes_day;
    }

    private function qualificationValid(CrewMember $member, Flight $flight): bool
    {
        if (! in_array($member->role, ['captain', 'first_officer'], true)) {
            return true;
        }

        if (! $flight->aircraft?->aircraft_type_id) {
            return false;
        }

        $date = $flight->scheduled_departure_at->toDateString();

        return $member->qualifications
            ->where('aircraft_type_id', $flight->aircraft->aircraft_type_id)
            ->where('qualification_type', 'type_rating')
            ->where('status', 'active')
            ->contains(function ($qualification) use ($date): bool {
                return $qualification->valid_from->toDateString() <= $date
                    && (! $qualification->valid_until || $qualification->valid_until->toDateString() >= $date);
            });
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
