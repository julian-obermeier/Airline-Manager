<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\Airport;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Commercial\MarketingService;
use App\Services\Operations\AirportOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AirlineController extends Controller
{
    public function __construct(
        private readonly AirportOperationsService $airportOperations,
        private readonly MarketingService $marketing,
    ) {
    }

    public function create(Request $request): View|RedirectResponse
    {
        $world = $this->activeWorldFor($request);

        if (! $world) {
            return redirect()->route('worlds.index');
        }

        $existing = Airline::query()
            ->where('world_id', $world->id)
            ->where('owner_user_id', $request->user()->id)
            ->exists();

        if ($existing) {
            return redirect()->route('dashboard');
        }

        return view('airlines.create', [
            'world' => $world,
            'airports' => Airport::query()->orderBy('country_code')->orderBy('city')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $world = $this->activeWorldFor($request);

        if (! $world) {
            return redirect()->route('worlds.index');
        }

        if (Airline::query()->where('world_id', $world->id)->where('owner_user_id', $request->user()->id)->exists()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'home_airport_id' => ['required', 'string', 'exists:airports,id'],
            'iata_code' => [
                'nullable', 'string', 'size:2', 'regex:/^[A-Za-z0-9]{2}$/',
                Rule::unique('airlines', 'iata_code')->where(fn ($q) => $q->where('world_id', $world->id)),
            ],
            'icao_code' => [
                'nullable', 'string', 'size:3', 'regex:/^[A-Za-z0-9]{3}$/',
                Rule::unique('airlines', 'icao_code')->where(fn ($q) => $q->where('world_id', $world->id)),
            ],
            'callsign' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]+$/',
                Rule::unique('airlines', 'callsign')->where(fn ($q) => $q->where('world_id', $world->id)),
            ],
            'business_model' => ['required', Rule::in(['full_service', 'low_cost', 'regional', 'cargo', 'hybrid'])],
            'service_concept' => ['nullable', Rule::in(['economy', 'balanced', 'premium'])],
            'target_group' => ['nullable', Rule::in(['leisure', 'business', 'mixed'])],
        ]);

        $airport = Airport::findOrFail($validated['home_airport_id']);
        $startingCapitalMinor = (int) data_get($world->settings, 'starting_capital_minor', 5000000000);
        $currency = (string) data_get($world->settings, 'currency', 'EUR');

        DB::transaction(function () use ($request, $validated, $world, $airport, $startingCapitalMinor, $currency): void {
            $slugBase = Str::slug($validated['name']);
            $slug = $slugBase;
            $suffix = 2;

            while (Airline::query()->where('world_id', $world->id)->where('slug', $slug)->exists()) {
                $slug = $slugBase.'-'.$suffix++;
            }

            $airline = Airline::create([
                'world_id' => $world->id,
                'owner_user_id' => $request->user()->id,
                'home_airport_id' => $airport->id,
                'name' => $validated['name'],
                'slug' => $slug,
                'icao_code' => filled($validated['icao_code'] ?? null) ? strtoupper($validated['icao_code']) : null,
                'iata_code' => filled($validated['iata_code'] ?? null) ? strtoupper($validated['iata_code']) : null,
                'callsign' => filled($validated['callsign'] ?? null) ? strtoupper(trim($validated['callsign'])) : null,
                'country_code' => $airport->country_code,
                'base_currency' => $currency,
                'business_model' => $validated['business_model'],
                'service_concept' => $validated['service_concept'] ?? 'balanced',
                'target_group' => $validated['target_group'] ?? 'mixed',
                'starting_capital_minor' => $startingCapitalMinor,
                'status' => 'active',
                'branding' => [
                    'primary_color' => '#39b8ff',
                    'secondary_color' => '#0b1728',
                ],
            ]);

            $cash = LedgerAccount::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'code' => 'CASH',
                'name' => 'Bankguthaben',
                'type' => 'asset',
                'currency' => $currency,
                'is_system' => true,
            ]);

            $equity = LedgerAccount::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'code' => 'EQUITY',
                'name' => 'Eigenkapital',
                'type' => 'equity',
                'currency' => $currency,
                'is_system' => true,
            ]);

            $transaction = LedgerTransaction::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'idempotency_key' => 'airline-foundation:'.$airline->id,
                'reference_type' => 'airline_creation',
                'reference_id' => $airline->id,
                'description' => 'Startkapital bei Airline-Gründung',
                'occurred_at' => $world->simulated_at ?? now(),
                'posted_at' => now(),
                'metadata' => ['source' => 'system'],
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $cash->id,
                'amount_minor' => $startingCapitalMinor,
                'memo' => 'Einzahlung Startkapital',
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $equity->id,
                'amount_minor' => -$startingCapitalMinor,
                'memo' => 'Gegenkonto Startkapital',
            ]);

            $this->airportOperations->ensureStation($airline, $airport, 'base');
            $this->marketing->ensureProfile($airline);
        });

        return redirect()->route('dashboard')->with('success', 'Deine Airline wurde gegründet.');
    }

    private function activeWorldFor(Request $request): ?World
    {
        $worldId = $request->session()->get('active_world_id');

        if (! $worldId) {
            return null;
        }

        $membershipExists = DB::table('world_memberships')
            ->where('world_id', $worldId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        return $membershipExists ? World::find($worldId) : null;
    }
}
