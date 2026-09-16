<?php

namespace App\Services\Operations;

use App\Models\Aircraft;
use App\Models\AircraftMarketOffer;
use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Commercial\RevenueManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProcurementService
{
    public function __construct(private readonly RevenueManagementService $revenueManagement)
    {
    }

    public function orderNewPurchase(Airline $airline, AircraftType $type, ?string $registration = null, ?Carbon $now = null): AircraftProcurement
    {
        $now ??= now();
        $priceMinor = (int) $type->reference_purchase_price_minor;

        if ($priceMinor <= 0) {
            throw new RuntimeException('Für dieses Flugzeugmuster ist kein Kaufpreis hinterlegt.');
        }

        $this->ensureCash($airline, $priceMinor);
        $registration = $this->resolveRegistration($airline, $registration);
        $deliveryDays = $this->newAircraftDeliveryDays($type);

        return DB::transaction(function () use ($airline, $type, $registration, $priceMinor, $now, $deliveryDays): AircraftProcurement {
            $procurement = AircraftProcurement::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'aircraft_type_id' => $type->id,
                'procurement_type' => 'purchase_new',
                'status' => 'ordered',
                'registration' => $registration,
                'total_price_minor' => $priceMinor,
                'upfront_payment_minor' => $priceMinor,
                'monthly_payment_minor' => 0,
                'ordered_at' => $now,
                'delivery_due_at' => $now->copy()->addDays($deliveryDays),
                'delivery_condition_percent' => 100,
                'initial_flight_hours' => 0,
                'initial_flight_cycles' => 0,
                'manufactured_on' => $now->copy()->toDateString(),
                'currency' => $airline->base_currency,
                'metadata' => [
                    'delivery_days' => $deliveryDays,
                    'source' => 'manufacturer',
                ],
            ]);

            $this->postPurchasePrepayment($airline, $procurement, $priceMinor, 'Neuflugzeug-Bestellung '.$type->manufacturer.' '.$type->model);

            return $procurement;
        });
    }

    public function orderLease(Airline $airline, AircraftType $type, int $termMonths, ?string $registration = null, ?Carbon $now = null): AircraftProcurement
    {
        $now ??= now();
        $termMonths = in_array($termMonths, [36, 60, 84], true) ? $termMonths : 60;
        $referencePrice = max(1, (int) $type->reference_purchase_price_minor);
        $monthly = (int) ceil($referencePrice * 0.0085);
        $setupFee = $monthly * 2;

        $this->ensureCash($airline, $setupFee);
        $registration = $this->resolveRegistration($airline, $registration);
        $deliveryDays = max(7, min(21, 8 + (int) ceil(((int) ($type->typical_seats ?? 150)) / 30)));

        return DB::transaction(function () use ($airline, $type, $termMonths, $registration, $referencePrice, $monthly, $setupFee, $now, $deliveryDays): AircraftProcurement {
            $procurement = AircraftProcurement::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'aircraft_type_id' => $type->id,
                'procurement_type' => 'lease',
                'status' => 'ordered',
                'registration' => $registration,
                'total_price_minor' => $referencePrice,
                'upfront_payment_minor' => $setupFee,
                'monthly_payment_minor' => $monthly,
                'lease_term_months' => $termMonths,
                'ordered_at' => $now,
                'delivery_due_at' => $now->copy()->addDays($deliveryDays),
                'delivery_condition_percent' => 100,
                'initial_flight_hours' => 0,
                'initial_flight_cycles' => 0,
                'manufactured_on' => $now->copy()->toDateString(),
                'currency' => $airline->base_currency,
                'metadata' => [
                    'delivery_days' => $deliveryDays,
                    'source' => 'operating_lease',
                ],
            ]);

            $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
            $expense = $this->account($airline, 'LEASE_EXPENSE', 'Leasingkosten', 'expense');
            $transaction = LedgerTransaction::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'idempotency_key' => 'lease-setup:'.$procurement->id,
                'reference_type' => 'aircraft_lease_setup',
                'reference_id' => $procurement->id,
                'description' => 'Leasing-Bereitstellungsgebühr '.$type->manufacturer.' '.$type->model,
                'occurred_at' => $now,
                'posted_at' => now(),
                'metadata' => ['term_months' => $termMonths, 'monthly_payment_minor' => $monthly],
            ]);
            LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $expense->id, 'amount_minor' => $setupFee, 'memo' => 'Leasingbereitstellung']);
            LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $cash->id, 'amount_minor' => -$setupFee, 'memo' => 'Leasingbereitstellung']);

            return $procurement;
        });
    }

    public function buyUsed(Airline $airline, AircraftMarketOffer $offer, ?string $registration = null, ?Carbon $now = null): AircraftProcurement
    {
        $now ??= now();

        return DB::transaction(function () use ($airline, $offer, $registration, $now): AircraftProcurement {
            $lockedOffer = AircraftMarketOffer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($lockedOffer->world_id !== $airline->world_id || $lockedOffer->status !== 'available') {
                throw new RuntimeException('Dieses Gebrauchtflugzeug ist nicht mehr verfügbar.');
            }

            $priceMinor = (int) $lockedOffer->price_minor;
            $this->ensureCash($airline, $priceMinor);
            $resolvedRegistration = $this->resolveRegistration($airline, $registration);
            $deliveryDays = 2 + ((int) sprintf('%u', crc32($lockedOffer->id)) % 4);

            $procurement = AircraftProcurement::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'aircraft_type_id' => $lockedOffer->aircraft_type_id,
                'market_offer_id' => $lockedOffer->id,
                'procurement_type' => 'purchase_used',
                'status' => 'ordered',
                'registration' => $resolvedRegistration,
                'total_price_minor' => $priceMinor,
                'upfront_payment_minor' => $priceMinor,
                'monthly_payment_minor' => 0,
                'ordered_at' => $now,
                'delivery_due_at' => $now->copy()->addDays($deliveryDays),
                'delivery_condition_percent' => $lockedOffer->condition_percent,
                'initial_flight_hours' => $lockedOffer->flight_hours,
                'initial_flight_cycles' => $lockedOffer->flight_cycles,
                'manufactured_on' => $lockedOffer->manufactured_on,
                'currency' => $lockedOffer->currency,
                'metadata' => ['delivery_days' => $deliveryDays, 'serial_number' => $lockedOffer->serial_number, 'source' => 'used_market'],
            ]);

            $lockedOffer->forceFill(['status' => 'reserved'])->save();
            $this->postPurchasePrepayment($airline, $procurement, $priceMinor, 'Gebrauchtflugzeug '.$lockedOffer->serial_number);

            return $procurement;
        });
    }

    public function processWorld(World $world, Carbon $simulationNow): array
    {
        $summary = ['deliveries' => 0, 'lease_payments' => 0, 'leases_ended' => 0];

        AircraftProcurement::query()
            ->where('world_id', $world->id)
            ->where('status', 'ordered')
            ->where('delivery_due_at', '<=', $simulationNow)
            ->orderBy('delivery_due_at')
            ->each(function (AircraftProcurement $procurement) use ($simulationNow, &$summary): void {
                if ($this->deliver($procurement, $simulationNow)) {
                    $summary['deliveries']++;
                }
            });

        AircraftProcurement::query()
            ->where('world_id', $world->id)
            ->where('procurement_type', 'lease')
            ->where('status', 'delivered')
            ->whereNotNull('next_payment_at')
            ->where('next_payment_at', '<=', $simulationNow)
            ->orderBy('next_payment_at')
            ->each(function (AircraftProcurement $procurement) use ($simulationNow, &$summary): void {
                $result = $this->processLease($procurement, $simulationNow);
                $summary['lease_payments'] += $result['payments'];
                $summary['leases_ended'] += $result['ended'] ? 1 : 0;
            });

        return $summary;
    }

    public function cashBalanceMinor(Airline $airline): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');
    }

    private function deliver(AircraftProcurement $procurement, Carbon $simulationNow): bool
    {
        return DB::transaction(function () use ($procurement, $simulationNow): bool {
            $locked = AircraftProcurement::query()->with(['airline', 'type', 'marketOffer'])->lockForUpdate()->findOrFail($procurement->id);
            if ($locked->status !== 'ordered') {
                return false;
            }

            $airline = $locked->airline;
            $type = $locked->type;
            $totalSeats = max(1, (int) ($type->typical_seats ?? 1));
            $cabins = $this->revenueManagement->cabinLayout($totalSeats, $airline->business_model);

            $aircraft = Aircraft::create([
                'world_id' => $locked->world_id,
                'airline_id' => $locked->airline_id,
                'aircraft_type_id' => $locked->aircraft_type_id,
                'current_airport_id' => $airline->home_airport_id,
                'registration' => $locked->registration,
                'serial_number' => $locked->marketOffer?->serial_number ?? 'NEW-'.$locked->id,
                'manufactured_on' => $locked->manufactured_on,
                'engine_variant' => null,
                'flight_hours' => $locked->initial_flight_hours,
                'flight_cycles' => $locked->initial_flight_cycles,
                'condition_percent' => $locked->delivery_condition_percent,
                'status' => 'available',
                'ownership_type' => $locked->procurement_type === 'lease' ? 'leased' : 'owned',
                'acquisition_price_minor' => $locked->procurement_type === 'lease' ? null : $locked->total_price_minor,
                'currency' => $locked->currency,
                'configuration' => ['seats' => $totalSeats, 'cabins' => $cabins],
                'metadata' => ['procurement_id' => $locked->id, 'procurement_type' => $locked->procurement_type],
            ]);

            if ($locked->procurement_type !== 'lease') {
                $prepayment = $this->account($airline, 'AIRCRAFT_PREPAYMENTS', 'Flugzeug-Vorauszahlungen', 'asset');
                $fleet = $this->account($airline, 'FLEET', 'Flottenvermögen', 'asset');
                $transaction = LedgerTransaction::create([
                    'world_id' => $locked->world_id,
                    'airline_id' => $locked->airline_id,
                    'idempotency_key' => 'aircraft-delivery:'.$locked->id,
                    'reference_type' => 'aircraft_delivery',
                    'reference_id' => $aircraft->id,
                    'description' => 'Auslieferung '.$type->manufacturer.' '.$type->model.' · '.$locked->registration,
                    'occurred_at' => $simulationNow,
                    'posted_at' => now(),
                    'metadata' => ['procurement_id' => $locked->id],
                ]);
                LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $fleet->id, 'amount_minor' => $locked->total_price_minor, 'memo' => 'Aktivierung ausgeliefertes Flugzeug']);
                LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $prepayment->id, 'amount_minor' => -$locked->total_price_minor, 'memo' => 'Auflösung Vorauszahlung']);
            }

            if ($locked->marketOffer) {
                $locked->marketOffer->forceFill(['status' => 'sold'])->save();
            }

            $updates = [
                'status' => 'delivered',
                'delivered_aircraft_id' => $aircraft->id,
                'delivered_at' => $simulationNow,
            ];
            if ($locked->procurement_type === 'lease') {
                $updates['next_payment_at'] = $simulationNow->copy()->addMonthNoOverflow();
                $updates['lease_ends_at'] = $simulationNow->copy()->addMonthsNoOverflow((int) $locked->lease_term_months);
            }
            $locked->forceFill($updates)->save();

            return true;
        });
    }

    private function processLease(AircraftProcurement $procurement, Carbon $simulationNow): array
    {
        $payments = 0;
        $ended = false;

        DB::transaction(function () use ($procurement, $simulationNow, &$payments, &$ended): void {
            $locked = AircraftProcurement::query()->with(['airline', 'deliveredAircraft'])->lockForUpdate()->findOrFail($procurement->id);
            if ($locked->status !== 'delivered' || $locked->procurement_type !== 'lease') {
                return;
            }

            while ($locked->next_payment_at && $locked->next_payment_at->lessThanOrEqualTo($simulationNow)
                && (!$locked->lease_ends_at || $locked->next_payment_at->lessThan($locked->lease_ends_at))) {
                $paymentAt = $locked->next_payment_at->copy();
                $key = 'lease-payment:'.$locked->id.':'.$paymentAt->format('Ymd');

                if (! LedgerTransaction::query()->where('world_id', $locked->world_id)->where('idempotency_key', $key)->exists()) {
                    $cash = $this->account($locked->airline, 'CASH', 'Bankguthaben', 'asset');
                    $expense = $this->account($locked->airline, 'LEASE_EXPENSE', 'Leasingkosten', 'expense');
                    $transaction = LedgerTransaction::create([
                        'world_id' => $locked->world_id,
                        'airline_id' => $locked->airline_id,
                        'idempotency_key' => $key,
                        'reference_type' => 'aircraft_lease_payment',
                        'reference_id' => $locked->id,
                        'description' => 'Leasingrate '.$locked->registration,
                        'occurred_at' => $paymentAt,
                        'posted_at' => now(),
                        'metadata' => ['procurement_id' => $locked->id],
                    ]);
                    LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $expense->id, 'amount_minor' => $locked->monthly_payment_minor, 'memo' => 'Monatliche Leasingrate']);
                    LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $cash->id, 'amount_minor' => -$locked->monthly_payment_minor, 'memo' => 'Monatliche Leasingrate']);
                    $payments++;
                }

                $locked->next_payment_at = $locked->next_payment_at->copy()->addMonthNoOverflow();
            }

            if ($locked->lease_ends_at && $simulationNow->greaterThanOrEqualTo($locked->lease_ends_at)) {
                $locked->status = 'lease_ended';
                $locked->next_payment_at = null;
                if ($locked->deliveredAircraft) {
                    $locked->deliveredAircraft->forceFill(['status' => 'grounded'])->save();
                }
                $ended = true;
            }

            $locked->save();
        });

        return ['payments' => $payments, 'ended' => $ended];
    }

    private function postPurchasePrepayment(Airline $airline, AircraftProcurement $procurement, int $amountMinor, string $description): void
    {
        $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
        $prepayment = $this->account($airline, 'AIRCRAFT_PREPAYMENTS', 'Flugzeug-Vorauszahlungen', 'asset');
        $transaction = LedgerTransaction::create([
            'world_id' => $airline->world_id,
            'airline_id' => $airline->id,
            'idempotency_key' => 'aircraft-order:'.$procurement->id,
            'reference_type' => 'aircraft_order',
            'reference_id' => $procurement->id,
            'description' => $description,
            'occurred_at' => $procurement->ordered_at,
            'posted_at' => now(),
            'metadata' => ['procurement_type' => $procurement->procurement_type],
        ]);
        LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $prepayment->id, 'amount_minor' => $amountMinor, 'memo' => 'Vorauszahlung Flugzeug']);
        LedgerEntry::create(['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $cash->id, 'amount_minor' => -$amountMinor, 'memo' => 'Zahlung Flugzeugbestellung']);
    }

    private function ensureCash(Airline $airline, int $requiredMinor): void
    {
        if ($this->cashBalanceMinor($airline) < $requiredMinor) {
            throw ValidationException::withMessages(['procurement' => 'Die Liquidität reicht für diese Beschaffung nicht aus.']);
        }
    }

    private function resolveRegistration(Airline $airline, ?string $registration): string
    {
        if ($registration) {
            $registration = strtoupper(trim($registration));
            $used = Aircraft::query()->where('world_id', $airline->world_id)->where('registration', $registration)->exists()
                || AircraftProcurement::query()->where('world_id', $airline->world_id)->where('registration', $registration)->whereNotIn('status', ['cancelled'])->exists();
            if ($used) {
                throw ValidationException::withMessages(['registration' => 'Dieses Kennzeichen ist in der Spielwelt bereits vergeben oder reserviert.']);
            }
            return $registration;
        }

        $prefix = match ($airline->country_code) {
            'DE' => 'D-A', 'AT' => 'OE-L', 'CH' => 'HB-J', 'NL' => 'PH-', 'GB' => 'G-', default => strtoupper($airline->country_code).'-',
        };

        do {
            $letters = '';
            $length = $airline->country_code === 'GB' ? 4 : 3;
            for ($i = 0; $i < $length; $i++) {
                $letters .= chr(random_int(65, 90));
            }
            $candidate = $prefix.$letters;
        } while (
            Aircraft::query()->where('world_id', $airline->world_id)->where('registration', $candidate)->exists()
            || AircraftProcurement::query()->where('world_id', $airline->world_id)->where('registration', $candidate)->whereNotIn('status', ['cancelled'])->exists()
        );

        return $candidate;
    }

    private function newAircraftDeliveryDays(AircraftType $type): int
    {
        $seats = (int) ($type->typical_seats ?? 150);
        return match (true) {
            $seats <= 140 => 18,
            $seats <= 190 => 24,
            $seats <= 260 => 32,
            default => 45,
        };
    }

    private function account(Airline $airline, string $code, string $name, string $type): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            ['airline_id' => $airline->id, 'code' => $code],
            ['world_id' => $airline->world_id, 'name' => $name, 'type' => $type, 'currency' => $airline->base_currency, 'is_system' => true]
        );
    }
}
