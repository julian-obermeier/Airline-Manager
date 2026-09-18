<?php

namespace App\Services\Operations;

use App\Models\Aircraft;
use App\Models\AircraftMaintenanceEvent;
use App\Models\AircraftMarketOffer;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FleetManagementService
{
    public function estimatedSaleValueMinor(Aircraft $aircraft, ?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $aircraft->loadMissing('type');

        $baseValue = max(
            1000000,
            (int) ($aircraft->acquisition_price_minor
                ?: $aircraft->type?->reference_purchase_price_minor
                ?: 1000000)
        );

        $ageYears = $aircraft->manufactured_on
            ? max(0.0, $aircraft->manufactured_on->diffInDays($asOf) / 365.25)
            : 0.0;

        $ageFactor = max(0.38, 1.0 - ($ageYears * 0.045));
        $hoursFactor = max(0.70, 1.0 - (((float) $aircraft->flight_hours) / 120000));
        $condition = min(100, max(0, (float) $aircraft->condition_percent));
        $conditionFactor = 0.55 + (($condition / 100) * 0.45);
        $brokerFactor = 0.92;

        return max(
            (int) round($baseValue * 0.20),
            (int) round($baseValue * $ageFactor * $hoursFactor * $conditionFactor * $brokerFactor)
        );
    }

    public function saleBlockReason(Aircraft $aircraft, ?Carbon $asOf = null): ?string
    {
        $asOf ??= now();

        if ($aircraft->ownership_type !== 'owned') {
            return 'Leasingflugzeuge gehören nicht der Airline und können nicht verkauft werden.';
        }

        if (in_array($aircraft->status, ['in_flight', 'maintenance', 'sold'], true)) {
            return match ($aircraft->status) {
                'in_flight' => 'Das Flugzeug befindet sich aktuell im Flug.',
                'maintenance' => 'Das Flugzeug befindet sich aktuell in Wartung.',
                default => 'Das Flugzeug wurde bereits verkauft.',
            };
        }

        $activeFlights = Flight::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereIn('status', ['scheduled', 'boarding', 'departed', 'in_air'])
            ->exists();

        if ($activeFlights) {
            return 'Für dieses Flugzeug sind noch aktive oder zukünftige Flüge geplant.';
        }

        $activeSchedule = FlightSchedule::query()
            ->where('aircraft_id', $aircraft->id)
            ->where('status', 'active')
            ->exists();

        if ($activeSchedule) {
            return 'Das Flugzeug ist noch einem aktiven wiederkehrenden Flugplan zugeordnet.';
        }

        $activeMaintenance = AircraftMaintenanceEvent::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereIn('status', ['planned', 'in_progress'])
            ->exists();

        if ($activeMaintenance) {
            return 'Für dieses Flugzeug besteht noch ein geplantes oder laufendes Wartungsereignis.';
        }

        return null;
    }

    public function sellOwnedAircraft(Airline $airline, Aircraft $aircraft, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        if ($aircraft->world_id !== $airline->world_id || $aircraft->airline_id !== $airline->id) {
            abort(404);
        }

        if ($reason = $this->saleBlockReason($aircraft, $asOf)) {
            throw ValidationException::withMessages(['aircraft' => $reason]);
        }

        return DB::transaction(function () use ($airline, $aircraft, $asOf): int {
            $locked = Aircraft::query()
                ->with(['type', 'currentAirport'])
                ->lockForUpdate()
                ->findOrFail($aircraft->id);

            if ($locked->airline_id !== $airline->id || $locked->ownership_type !== 'owned') {
                throw ValidationException::withMessages([
                    'aircraft' => 'Dieses Flugzeug steht nicht mehr für einen Verkauf zur Verfügung.',
                ]);
            }

            if ($reason = $this->saleBlockReason($locked, $asOf)) {
                throw ValidationException::withMessages(['aircraft' => $reason]);
            }

            $saleValue = $this->estimatedSaleValueMinor($locked, $asOf);
            $bookValue = max(
                0,
                (int) ($locked->acquisition_price_minor
                    ?: $locked->type?->reference_purchase_price_minor
                    ?: $saleValue)
            );
            $difference = $saleValue - $bookValue;

            $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
            $fleet = $this->account($airline, 'FLEET', 'Flottenvermögen', 'asset');

            $transaction = LedgerTransaction::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'idempotency_key' => 'aircraft-sale:'.$locked->id,
                'reference_type' => 'aircraft_sale',
                'reference_id' => $locked->id,
                'description' => 'Flugzeugverkauf '.$locked->registration.' · '.$locked->type?->manufacturer.' '.$locked->type?->model,
                'occurred_at' => $asOf,
                'posted_at' => now(),
                'metadata' => [
                    'registration' => $locked->registration,
                    'sale_value_minor' => $saleValue,
                    'book_value_minor' => $bookValue,
                    'condition_percent' => (float) $locked->condition_percent,
                ],
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $cash->id,
                'amount_minor' => $saleValue,
                'memo' => 'Verkaufserlös '.$locked->registration,
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $fleet->id,
                'amount_minor' => -$bookValue,
                'memo' => 'Abgang Flottenvermögen '.$locked->registration,
            ]);

            if ($difference > 0) {
                $gain = $this->account($airline, 'AIRCRAFT_SALE_GAIN', 'Gewinne aus Flugzeugverkäufen', 'income');
                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $gain->id,
                    'amount_minor' => -$difference,
                    'memo' => 'Veräußerungsgewinn '.$locked->registration,
                ]);
            } elseif ($difference < 0) {
                $loss = $this->account($airline, 'AIRCRAFT_SALE_LOSS', 'Verluste aus Flugzeugverkäufen', 'expense');
                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $loss->id,
                    'amount_minor' => abs($difference),
                    'memo' => 'Veräußerungsverlust '.$locked->registration,
                ]);
            }

            $serial = $locked->serial_number ?: 'RESALE-'.$locked->id;
            AircraftMarketOffer::query()->updateOrCreate(
                ['world_id' => $airline->world_id, 'serial_number' => $serial],
                [
                    'aircraft_type_id' => $locked->aircraft_type_id,
                    'location_airport_id' => $locked->current_airport_id,
                    'manufactured_on' => $locked->manufactured_on,
                    'flight_hours' => $locked->flight_hours,
                    'flight_cycles' => $locked->flight_cycles,
                    'condition_percent' => $locked->condition_percent,
                    'price_minor' => (int) round($saleValue * 1.12),
                    'currency' => $locked->currency,
                    'status' => 'available',
                    'metadata' => [
                        'source' => 'player_resale',
                        'former_airline_id' => $airline->id,
                        'former_registration' => $locked->registration,
                    ],
                ]
            );

            $metadata = $locked->metadata ?? [];
            $metadata['sale'] = [
                'sold_at' => $asOf->toIso8601String(),
                'sale_value_minor' => $saleValue,
                'former_airline_id' => $airline->id,
            ];

            $locked->forceFill([
                'airline_id' => null,
                'status' => 'sold',
                'metadata' => $metadata,
            ])->save();

            return $saleValue;
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
