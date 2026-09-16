<?php

namespace App\Services\Operations;

use App\Models\Aircraft;
use App\Models\AircraftMaintenanceEvent;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class MaintenanceService
{
    public function snapshot(Aircraft $aircraft): array
    {
        $aircraft->loadMissing('type');

        $aCheck = $this->checkSnapshot($aircraft, 'a_check');
        $cCheck = $this->checkSnapshot($aircraft, 'c_check');
        $condition = (float) $aircraft->condition_percent;
        $groundingThreshold = (float) config('maintenance.condition_grounding_percent', 70);
        $warningThreshold = (float) config('maintenance.condition_warning_percent', 85);

        $recommended = match (true) {
            $cCheck['overdue_hard'] || $cCheck['due'] => 'c_check',
            $aCheck['overdue_hard'] || $aCheck['due'] => 'a_check',
            $condition <= $warningThreshold => 'repair',
            $cCheck['progress'] >= 0.80 => 'c_check',
            $aCheck['progress'] >= 0.80 => 'a_check',
            default => null,
        };

        return [
            'condition_percent' => $condition,
            'condition_warning' => $condition <= $warningThreshold,
            'condition_grounding' => $condition <= $groundingThreshold,
            'a_check' => $aCheck,
            'c_check' => $cCheck,
            'recommended_check' => $recommended,
            'quotes' => [
                'a_check' => $this->quote($aircraft, 'a_check'),
                'c_check' => $this->quote($aircraft, 'c_check'),
                'repair' => $this->quote($aircraft, 'repair'),
            ],
        ];
    }

    public function quote(Aircraft $aircraft, string $checkType): array
    {
        $configuration = config('maintenance.checks.'.$checkType);

        if (! is_array($configuration)) {
            throw new InvalidArgumentException('Unbekannter Wartungstyp.');
        }

        $aircraft->loadMissing('type');
        $seats = max(1, (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 1));
        $baseCost = max(0, (int) ($configuration['base_cost_minor'] ?? 0));
        $seatCost = max(0, (int) ($configuration['seat_cost_minor'] ?? 0));
        $durationHours = max(1, (int) ($configuration['duration_hours'] ?? 1));

        if ($checkType === 'a_check' && $seats > 160) {
            $durationHours += 2;
        }

        if ($checkType === 'c_check' && $seats > 160) {
            $durationHours += (int) ceil(($seats - 160) / 40) * 12;
        }

        $costMinor = $baseCost + ($seats * $seatCost);

        if ($checkType === 'repair') {
            $conditionDeficit = max(0, 100 - (float) $aircraft->condition_percent);
            $costMinor += (int) ceil($conditionDeficit) * max(0, (int) ($configuration['condition_point_cost_minor'] ?? 0));
            $durationHours += min(36, (int) floor($conditionDeficit / 10) * 4);
        }

        return [
            'check_type' => $checkType,
            'label' => (string) ($configuration['label'] ?? $checkType),
            'duration_hours' => $durationHours,
            'cost_minor' => $costMinor,
            'currency' => $aircraft->currency ?: 'EUR',
        ];
    }

    public function schedule(Aircraft $aircraft, Airline $airline, string $checkType, Carbon $plannedStart): AircraftMaintenanceEvent
    {
        $quote = $this->quote($aircraft, $checkType);
        $plannedEnd = $plannedStart->copy()->addHours($quote['duration_hours']);

        if ($this->hasMaintenanceConflict($aircraft, $plannedStart, $plannedEnd)) {
            throw new RuntimeException('In diesem Zeitraum ist bereits eine andere Wartung für das Flugzeug geplant.');
        }

        if ($this->hasFlightConflict($aircraft, $plannedStart, $plannedEnd)) {
            throw new RuntimeException('Im gewählten Wartungsfenster ist das Flugzeug bereits für einen Flug eingeplant.');
        }

        $snapshot = $this->snapshot($aircraft);

        return AircraftMaintenanceEvent::create([
            'world_id' => $aircraft->world_id,
            'airline_id' => $airline->id,
            'aircraft_id' => $aircraft->id,
            'check_type' => $checkType,
            'status' => 'planned',
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'cost_minor' => $quote['cost_minor'],
            'currency' => $airline->base_currency,
            'metadata' => [
                'quoted_duration_hours' => $quote['duration_hours'],
                'scheduled_condition_percent' => (float) $aircraft->condition_percent,
                'a_check_progress' => $snapshot['a_check']['progress'],
                'c_check_progress' => $snapshot['c_check']['progress'],
                'source' => 'maintenance_planner',
            ],
        ]);
    }

    public function hasMaintenanceConflict(Aircraft $aircraft, Carbon $start, Carbon $end, ?string $excludeId = null): bool
    {
        return AircraftMaintenanceEvent::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereIn('status', ['planned', 'in_progress'])
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where('planned_start_at', '<', $end)
            ->where('planned_end_at', '>', $start)
            ->exists();
    }

    public function hasFlightConflict(Aircraft $aircraft, Carbon $start, Carbon $end): bool
    {
        return Flight::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('scheduled_departure_at', '<', $end)
            ->where('scheduled_arrival_at', '>', $start)
            ->exists();
    }

    public function processWorld(World $world, Carbon $simulationNow): array
    {
        $summary = [
            'maintenance_started' => 0,
            'maintenance_completed' => 0,
            'maintenance_grounded' => 0,
            'maintenance_cancelled_flights' => 0,
        ];

        AircraftMaintenanceEvent::query()
            ->with(['aircraft.type', 'airline'])
            ->where('world_id', $world->id)
            ->where('status', 'in_progress')
            ->where('planned_end_at', '<=', $simulationNow)
            ->orderBy('planned_end_at')
            ->each(function (AircraftMaintenanceEvent $event) use ($simulationNow, &$summary): void {
                if ($this->completeEvent($event, $simulationNow)) {
                    $summary['maintenance_completed']++;
                }
            });

        AircraftMaintenanceEvent::query()
            ->with(['aircraft.type', 'airline'])
            ->where('world_id', $world->id)
            ->where('status', 'planned')
            ->where('planned_start_at', '<=', $simulationNow)
            ->orderBy('planned_start_at')
            ->each(function (AircraftMaintenanceEvent $event) use ($simulationNow, &$summary): void {
                $result = $this->startEvent($event, $simulationNow);
                if ($result['started']) {
                    $summary['maintenance_started']++;
                    $summary['maintenance_cancelled_flights'] += $result['cancelled_flights'];
                }
            });

        Aircraft::query()
            ->with('type')
            ->where('world_id', $world->id)
            ->whereNotNull('airline_id')
            ->each(function (Aircraft $aircraft) use (&$summary): void {
                $before = $aircraft->status;
                $this->evaluateAircraft($aircraft);
                $aircraft->refresh();

                if ($before !== 'grounded' && $aircraft->status === 'grounded') {
                    $summary['maintenance_grounded']++;
                }
            });

        return $summary;
    }

    public function evaluateAircraft(Aircraft $aircraft): array
    {
        $snapshot = $this->snapshot($aircraft);
        $reason = null;

        if ($snapshot['condition_grounding']) {
            $reason = 'condition';
        } elseif ($snapshot['c_check']['overdue_hard']) {
            $reason = 'c_check_overdue';
        } elseif ($snapshot['a_check']['overdue_hard']) {
            $reason = 'a_check_overdue';
        }

        if (in_array($aircraft->status, ['in_flight', 'maintenance'], true)) {
            return $snapshot + ['grounding_reason' => $reason];
        }

        $metadata = $aircraft->metadata ?? [];

        if ($reason !== null) {
            $metadata['maintenance'] = array_merge($metadata['maintenance'] ?? [], [
                'grounded_by_system' => true,
                'grounded_reason' => $reason,
                'grounded_at' => data_get($metadata, 'maintenance.grounded_at', now()->toIso8601String()),
            ]);

            if ($aircraft->status !== 'grounded' || $aircraft->metadata !== $metadata) {
                $aircraft->forceFill([
                    'status' => 'grounded',
                    'metadata' => $metadata,
                ])->save();
            }
        } elseif ($aircraft->status === 'grounded' && data_get($metadata, 'maintenance.grounded_by_system')) {
            $metadata['maintenance'] = array_merge($metadata['maintenance'] ?? [], [
                'grounded_by_system' => false,
                'grounded_reason' => null,
                'released_at' => now()->toIso8601String(),
            ]);

            $aircraft->forceFill([
                'status' => 'available',
                'metadata' => $metadata,
            ])->save();
        }

        return $snapshot + ['grounding_reason' => $reason];
    }

    public function cashBalanceMinor(Airline $airline): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');
    }

    private function checkSnapshot(Aircraft $aircraft, string $checkType): array
    {
        $config = (array) config('maintenance.checks.'.$checkType, []);
        $intervalHours = max(1, (float) ($config['interval_hours'] ?? 1));
        $intervalCycles = max(1, (int) ($config['interval_cycles'] ?? 1));
        $graceHours = max(0, (float) ($config['grace_hours'] ?? 0));
        $graceCycles = max(0, (int) ($config['grace_cycles'] ?? 0));
        $baselineHours = (float) data_get($aircraft->metadata, 'maintenance.'.$checkType.'_baseline_hours', 0);
        $baselineCycles = (int) data_get($aircraft->metadata, 'maintenance.'.$checkType.'_baseline_cycles', 0);
        $usedHours = max(0, (float) $aircraft->flight_hours - $baselineHours);
        $usedCycles = max(0, (int) $aircraft->flight_cycles - $baselineCycles);
        $hoursProgress = $usedHours / $intervalHours;
        $cyclesProgress = $usedCycles / $intervalCycles;
        $progress = max($hoursProgress, $cyclesProgress);
        $due = $usedHours >= $intervalHours || $usedCycles >= $intervalCycles;
        $overdueHard = $usedHours >= ($intervalHours + $graceHours)
            || $usedCycles >= ($intervalCycles + $graceCycles);

        return [
            'label' => (string) ($config['label'] ?? $checkType),
            'interval_hours' => $intervalHours,
            'interval_cycles' => $intervalCycles,
            'used_hours' => round($usedHours, 2),
            'used_cycles' => $usedCycles,
            'remaining_hours' => round($intervalHours - $usedHours, 2),
            'remaining_cycles' => $intervalCycles - $usedCycles,
            'progress' => round($progress, 4),
            'progress_percent' => round(min(1.5, $progress) * 100, 1),
            'due' => $due,
            'overdue_hard' => $overdueHard,
            'status' => $overdueHard ? 'grounding' : ($due ? 'due' : ($progress >= 0.80 ? 'soon' : 'ok')),
        ];
    }

    private function startEvent(AircraftMaintenanceEvent $event, Carbon $simulationNow): array
    {
        $aircraft = $event->aircraft;

        if (! $aircraft || $event->status !== 'planned' || $aircraft->status === 'in_flight') {
            return ['started' => false, 'cancelled_flights' => 0];
        }

        $durationHours = max(1, (int) data_get($event->metadata, 'quoted_duration_hours', 1));
        $effectiveEnd = $simulationNow->copy()->addHours($durationHours);

        if ($event->planned_end_at->greaterThan($effectiveEnd)) {
            $effectiveEnd = $event->planned_end_at->copy();
        }

        $cancelled = 0;

        DB::transaction(function () use ($event, $aircraft, $simulationNow, $effectiveEnd, &$cancelled): void {
            $lockedEvent = AircraftMaintenanceEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($lockedEvent->status !== 'planned') {
                return;
            }

            $lockedAircraft = Aircraft::query()->lockForUpdate()->findOrFail($aircraft->id);
            if ($lockedAircraft->status === 'in_flight') {
                return;
            }

            $flights = Flight::query()
                ->where('aircraft_id', $lockedAircraft->id)
                ->whereIn('status', ['scheduled', 'boarding'])
                ->where('scheduled_departure_at', '<', $effectiveEnd)
                ->where('scheduled_arrival_at', '>', $simulationNow)
                ->lockForUpdate()
                ->get();

            foreach ($flights as $flight) {
                $data = $flight->operational_data ?? [];
                $data['operations'] = array_merge($data['operations'] ?? [], [
                    'cancelled_at' => $simulationNow->toIso8601String(),
                    'cancellation_reason' => 'maintenance_window',
                    'maintenance_event_id' => $lockedEvent->id,
                ]);

                $flight->forceFill([
                    'status' => 'cancelled',
                    'operational_data' => $data,
                ])->save();
                $cancelled++;
            }

            $lockedEvent->forceFill([
                'status' => 'in_progress',
                'actual_start_at' => $simulationNow,
                'planned_end_at' => $effectiveEnd,
                'condition_before' => $lockedAircraft->condition_percent,
            ])->save();

            $lockedAircraft->forceFill(['status' => 'maintenance'])->save();
        });

        $event->refresh();

        return [
            'started' => $event->status === 'in_progress',
            'cancelled_flights' => $cancelled,
        ];
    }

    private function completeEvent(AircraftMaintenanceEvent $event, Carbon $simulationNow): bool
    {
        $completed = false;
        $aircraftId = $event->aircraft_id;

        DB::transaction(function () use ($event, $simulationNow, &$completed): void {
            $lockedEvent = AircraftMaintenanceEvent::query()
                ->with(['aircraft.type', 'airline'])
                ->lockForUpdate()
                ->findOrFail($event->id);

            if ($lockedEvent->status !== 'in_progress' || ! $lockedEvent->aircraft || ! $lockedEvent->airline) {
                return;
            }

            $aircraft = Aircraft::query()->lockForUpdate()->findOrFail($lockedEvent->aircraft_id);
            $before = (float) $aircraft->condition_percent;
            $after = $this->conditionAfterMaintenance($aircraft, $lockedEvent->check_type);
            $metadata = $aircraft->metadata ?? [];
            $maintenance = $metadata['maintenance'] ?? [];

            if ($lockedEvent->check_type === 'a_check') {
                $maintenance['a_check_baseline_hours'] = (float) $aircraft->flight_hours;
                $maintenance['a_check_baseline_cycles'] = (int) $aircraft->flight_cycles;
                $maintenance['last_a_check_at'] = $simulationNow->toIso8601String();
            }

            if ($lockedEvent->check_type === 'c_check') {
                foreach (['a_check', 'c_check'] as $type) {
                    $maintenance[$type.'_baseline_hours'] = (float) $aircraft->flight_hours;
                    $maintenance[$type.'_baseline_cycles'] = (int) $aircraft->flight_cycles;
                }
                $maintenance['last_a_check_at'] = $simulationNow->toIso8601String();
                $maintenance['last_c_check_at'] = $simulationNow->toIso8601String();
            }

            if ($lockedEvent->check_type === 'repair') {
                $maintenance['last_repair_at'] = $simulationNow->toIso8601String();
            }

            $maintenance['grounded_by_system'] = false;
            $maintenance['grounded_reason'] = null;
            $metadata['maintenance'] = $maintenance;

            $this->postMaintenanceCost($lockedEvent, $lockedEvent->airline, $simulationNow);

            $aircraft->forceFill([
                'condition_percent' => $after,
                'status' => 'available',
                'metadata' => $metadata,
            ])->save();

            $lockedEvent->forceFill([
                'status' => 'completed',
                'actual_end_at' => $simulationNow,
                'condition_before' => $lockedEvent->condition_before ?? $before,
                'condition_after' => $after,
                'flight_hours_snapshot' => $aircraft->flight_hours,
                'flight_cycles_snapshot' => $aircraft->flight_cycles,
            ])->save();

            $completed = true;
        });

        if ($completed) {
            $aircraft = Aircraft::with('type')->find($aircraftId);
            if ($aircraft) {
                $this->evaluateAircraft($aircraft);
            }
        }

        return $completed;
    }

    private function conditionAfterMaintenance(Aircraft $aircraft, string $checkType): float
    {
        $current = (float) $aircraft->condition_percent;
        $config = (array) config('maintenance.checks.'.$checkType, []);

        return match ($checkType) {
            'a_check', 'c_check' => min(100, max($current, (float) ($config['minimum_condition_after'] ?? $current))),
            'repair' => min(100, $current + (float) ($config['condition_restore_percent'] ?? 0)),
            default => $current,
        };
    }

    private function postMaintenanceCost(AircraftMaintenanceEvent $event, Airline $airline, Carbon $occurredAt): void
    {
        if (LedgerTransaction::query()
            ->where('world_id', $event->world_id)
            ->where('idempotency_key', 'maintenance-completion:'.$event->id)
            ->exists()) {
            return;
        }

        $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
        $expense = $this->account($airline, 'MAINTENANCE_EXPENSE', 'Wartung & Instandhaltung', 'expense');
        $label = (string) config('maintenance.checks.'.$event->check_type.'.label', $event->check_type);

        $transaction = LedgerTransaction::create([
            'world_id' => $event->world_id,
            'airline_id' => $event->airline_id,
            'idempotency_key' => 'maintenance-completion:'.$event->id,
            'reference_type' => 'aircraft_maintenance',
            'reference_id' => $event->id,
            'description' => $label.' · '.$event->aircraft?->registration,
            'occurred_at' => $occurredAt,
            'posted_at' => now(),
            'metadata' => [
                'check_type' => $event->check_type,
                'aircraft_id' => $event->aircraft_id,
                'cost_minor' => $event->cost_minor,
            ],
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $expense->id,
            'amount_minor' => (int) $event->cost_minor,
            'memo' => 'Wartungsaufwand',
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $cash->id,
            'amount_minor' => -(int) $event->cost_minor,
            'memo' => 'Zahlung Wartungsbetrieb',
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
