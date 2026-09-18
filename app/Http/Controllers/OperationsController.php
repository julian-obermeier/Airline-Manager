<?php

namespace App\Http\Controllers;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Commercial\MarketingService;
use App\Services\Commercial\RevenueManagementService;
use App\Services\Operations\AirportOperationsService;
use App\Services\Operations\CrewService;
use App\Services\Operations\MaintenanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function __construct(
        private readonly RevenueManagementService $revenueManagement,
        private readonly MaintenanceService $maintenance,
        private readonly CrewService $crewService,
        private readonly AirportOperationsService $airportOperations,
        private readonly MarketingService $marketing,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $fleet = Aircraft::query()
            ->with(['type', 'currentAirport'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderBy('registration')
            ->get();

        $routes = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->withCount('flights')
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderByDesc('created_at')
            ->get();

        $flights = Flight::query()
            ->with(['route.origin', 'route.destination', 'aircraft.type', 'crewAssignments.crewMember'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderBy('scheduled_departure_at')
            ->limit(30)
            ->get();

        $routePricing = $routes->mapWithKeys(fn (AirlineRoute $route): array => [
            $route->id => $this->revenueManagement->routeFares($route, $airline->business_model),
        ]);

        $crewSnapshots = $flights->mapWithKeys(fn (Flight $flight): array => [
            $flight->id => $this->crewService->staffingSnapshot($flight),
        ]);

        return view('operations.index', [
            'world' => $world,
            'airline' => $airline,
            'cashBalanceMinor' => $this->cashBalanceMinor($airline),
            'aircraftTypes' => AircraftType::query()
                ->whereNotNull('reference_purchase_price_minor')
                ->orderBy('manufacturer')
                ->orderBy('model')
                ->get(),
            'airports' => Airport::query()->orderBy('country_code')->orderBy('city')->get(),
            'fleet' => $fleet,
            'routes' => $routes,
            'routePricing' => $routePricing,
            'flights' => $flights,
            'crewSnapshots' => $crewSnapshots,
        ]);
    }

    public function purchaseAircraft(Request $request): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $validated = $request->validate([
            'aircraft_type_id' => ['required', 'string', 'exists:aircraft_types,id'],
            'registration' => [
                'nullable',
                'string',
                'max:16',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('aircraft', 'registration')->where(fn ($query) => $query->where('world_id', $world->id)),
            ],
        ]);

        $type = AircraftType::findOrFail($validated['aircraft_type_id']);
        $priceMinor = (int) $type->reference_purchase_price_minor;

        if ($priceMinor <= 0) {
            throw ValidationException::withMessages([
                'aircraft_type_id' => 'Für dieses Flugzeugmuster ist aktuell kein Kaufpreis hinterlegt.',
            ]);
        }

        if ($this->cashBalanceMinor($airline) < $priceMinor) {
            throw ValidationException::withMessages([
                'aircraft_type_id' => 'Deine Airline verfügt nicht über genügend Liquidität für diesen Kauf.',
            ]);
        }

        $registration = filled($validated['registration'] ?? null)
            ? strtoupper($validated['registration'])
            : $this->generateRegistration($world, $airline);

        $totalSeats = max(1, (int) ($type->typical_seats ?? 1));
        $cabins = $this->revenueManagement->cabinLayout($totalSeats, $airline->business_model);

        DB::transaction(function () use ($world, $airline, $type, $priceMinor, $registration, $totalSeats, $cabins): void {
            $aircraft = Aircraft::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'aircraft_type_id' => $type->id,
                'current_airport_id' => $airline->home_airport_id,
                'registration' => $registration,
                'serial_number' => null,
                'manufactured_on' => null,
                'engine_variant' => null,
                'flight_hours' => 0,
                'flight_cycles' => 0,
                'condition_percent' => 100,
                'status' => 'available',
                'ownership_type' => 'owned',
                'acquisition_price_minor' => $priceMinor,
                'currency' => $airline->base_currency,
                'configuration' => [
                    'seats' => $totalSeats,
                    'cabins' => $cabins,
                ],
                'metadata' => [
                    'acquired_via' => 'new_aircraft_market',
                    'maintenance' => [
                        'a_check_baseline_hours' => 0,
                        'a_check_baseline_cycles' => 0,
                        'c_check_baseline_hours' => 0,
                        'c_check_baseline_cycles' => 0,
                        'grounded_by_system' => false,
                    ],
                ],
            ]);

            $cash = LedgerAccount::query()
                ->where('airline_id', $airline->id)
                ->where('code', 'CASH')
                ->lockForUpdate()
                ->firstOrFail();

            $fleetAsset = LedgerAccount::query()->firstOrCreate(
                ['airline_id' => $airline->id, 'code' => 'FLEET'],
                [
                    'world_id' => $world->id,
                    'name' => 'Flottenvermögen',
                    'type' => 'asset',
                    'currency' => $airline->base_currency,
                    'is_system' => true,
                ]
            );

            $transaction = LedgerTransaction::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'idempotency_key' => 'aircraft-purchase:'.$aircraft->id,
                'reference_type' => 'aircraft_purchase',
                'reference_id' => $aircraft->id,
                'description' => 'Kauf '.$type->manufacturer.' '.$type->model.' · '.$registration,
                'occurred_at' => now(),
                'posted_at' => now(),
                'metadata' => ['aircraft_type_id' => $type->id],
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $fleetAsset->id,
                'amount_minor' => $priceMinor,
                'memo' => 'Aktivierung Flugzeug',
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $cash->id,
                'amount_minor' => -$priceMinor,
                'memo' => 'Kaufpreis Flugzeug',
            ]);
        });

        return redirect()->route('operations.index')->with('success', 'Flugzeug wurde gekauft und deiner Flotte hinzugefügt.');
    }

    public function storeRoute(Request $request): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $validated = $request->validate([
            'origin_airport_id' => ['required', 'string', 'exists:airports,id'],
            'destination_airport_id' => ['required', 'string', 'different:origin_airport_id', 'exists:airports,id'],
        ]);

        $origin = Airport::findOrFail($validated['origin_airport_id']);
        $destination = Airport::findOrFail($validated['destination_airport_id']);

        $duplicate = AirlineRoute::query()
            ->where('airline_id', $airline->id)
            ->where('origin_airport_id', $origin->id)
            ->where('destination_airport_id', $destination->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'destination_airport_id' => 'Diese Route ist für deine Airline bereits angelegt.',
            ]);
        }

        $distanceKm = $this->distanceKm($origin, $destination);
        $plannedBlockMinutes = max(45, (int) ceil(($distanceKm / 780) * 60) + 45);

        $this->airportOperations->ensureStation(
            $airline,
            $origin,
            $origin->id === $airline->home_airport_id ? 'base' : 'outstation'
        );
        $this->airportOperations->ensureStation(
            $airline,
            $destination,
            $destination->id === $airline->home_airport_id ? 'base' : 'outstation'
        );

        $newRoute = AirlineRoute::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'distance_km' => $distanceKm,
            'planned_block_minutes' => $plannedBlockMinutes,
            'status' => 'active',
            'settings' => [
                'calculation' => 'great_circle_v1',
                'fares' => $this->revenueManagement->defaultFares($distanceKm, $airline->business_model),
                'demand_index' => $this->revenueManagement->demandIndex($origin, $destination),
            ],
        ]);

        $this->marketing->ensureRouteMetric($newRoute);

        return redirect()->route('operations.index')->with('success', 'Route wurde angelegt. Standardtarife, Marktnachfrage und Streckenbekanntheit wurden initialisiert.');
    }

    public function updateRouteFares(Request $request, AirlineRoute $route): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        abort_unless($route->world_id === $world->id && $route->airline_id === $airline->id, 404);

        $validated = $request->validate([
            'economy_fare' => ['required', 'numeric', 'min:10', 'max:5000'],
            'business_fare' => ['required', 'numeric', 'min:0', 'max:10000'],
            'first_fare' => ['required', 'numeric', 'min:0', 'max:20000'],
        ]);

        $settings = $route->settings ?? [];
        $settings['fares'] = [
            'economy_minor' => (int) round(((float) $validated['economy_fare']) * 100),
            'business_minor' => (int) round(((float) $validated['business_fare']) * 100),
            'first_minor' => (int) round(((float) $validated['first_fare']) * 100),
        ];
        $settings['pricing_updated_at'] = now()->toIso8601String();

        $route->forceFill(['settings' => $settings])->save();

        return redirect()->route('operations.index')->with('success', 'Ticketpreise wurden gespeichert. Sie gelten für neu geplante Flüge.');
    }

    public function scheduleFlight(Request $request): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $validated = $request->validate([
            'route_id' => ['required', 'string', 'exists:routes,id'],
            'aircraft_id' => ['required', 'string', 'exists:aircraft,id'],
            'flight_number' => ['required', 'string', 'min:2', 'max:12', 'regex:/^[A-Za-z0-9 -]+$/'],
            'scheduled_departure_at' => ['required', 'date', 'after:now'],
        ]);

        $route = AirlineRoute::query()
            ->where('id', $validated['route_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->firstOrFail();

        $aircraft = Aircraft::query()
            ->with('type')
            ->where('id', $validated['aircraft_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->firstOrFail();

        if ($aircraft->status !== 'available') {
            throw ValidationException::withMessages([
                'aircraft_id' => 'Dieses Flugzeug ist aktuell nicht verfügbar.',
            ]);
        }

        if ($aircraft->type?->range_km && (float) $route->distance_km > (float) $aircraft->type->range_km) {
            throw ValidationException::withMessages([
                'aircraft_id' => 'Die Reichweite dieses Flugzeugmusters reicht für die gewählte Route nicht aus.',
            ]);
        }

        $departure = Carbon::parse($validated['scheduled_departure_at']);
        $arrival = $departure->copy()->addMinutes($route->planned_block_minutes);

        $this->airportOperations->assertSlotsAvailable($world, $route, $departure, $arrival);

        if ($this->maintenance->hasMaintenanceConflict($aircraft, $departure, $arrival)) {
            throw ValidationException::withMessages([
                'scheduled_departure_at' => 'Der Flug überschneidet sich mit einem geplanten Wartungsfenster dieses Flugzeugs.',
            ]);
        }

        $overlap = Flight::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('scheduled_departure_at', '<', $arrival)
            ->where('scheduled_arrival_at', '>', $departure)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'scheduled_departure_at' => 'Das Flugzeug ist in diesem Zeitraum bereits für einen anderen Flug eingeplant.',
            ]);
        }

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

        $flight = Flight::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => strtoupper(trim($validated['flight_number'])),
            'scheduled_departure_at' => $departure,
            'scheduled_arrival_at' => $arrival,
            'status' => 'scheduled',
            'delay_minutes' => 0,
            'passengers_booked' => 0,
            'cargo_kg_booked' => 0,
            'operational_data' => [
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
            ],
        ]);

        $this->airportOperations->reserveFlight($flight);
        $crewSnapshot = $this->crewService->assignCrew($flight);
        $crewMessage = $crewSnapshot['complete']
            ? ' Crew wurde automatisch vollständig zugewiesen.'
            : ' Crew ist noch unvollständig; vor Abflug fehlen '.$crewSnapshot['missing']['total'].' Mitarbeiter.';

        return redirect()->route('operations.index')->with(
            'success',
            'Flug wurde geplant. Ticketpreise und Kabineninventar wurden eingefroren.'.$crewMessage
        );
    }

    private function activeContext(Request $request): ?array
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

        if (! $membershipExists) {
            return null;
        }

        $world = World::find($worldId);
        $airline = Airline::query()
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }

    private function cashBalanceMinor(Airline $airline): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');
    }

    private function distanceKm(Airport $origin, Airport $destination): float
    {
        $earthRadiusKm = 6371.0088;
        $lat1 = deg2rad((float) $origin->latitude);
        $lat2 = deg2rad((float) $destination->latitude);
        $deltaLat = deg2rad((float) $destination->latitude - (float) $origin->latitude);
        $deltaLon = deg2rad((float) $destination->longitude - (float) $origin->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return round($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function generateRegistration(World $world, Airline $airline): string
    {
        $prefix = match ($airline->country_code) {
            'DE' => 'D-A',
            'AT' => 'OE-L',
            'CH' => 'HB-J',
            'NL' => 'PH-',
            'GB' => 'G-',
            default => strtoupper($airline->country_code).'-',
        };

        do {
            $letters = '';
            $length = $airline->country_code === 'GB' ? 4 : 3;

            for ($i = 0; $i < $length; $i++) {
                $letters .= chr(random_int(65, 90));
            }

            $registration = $prefix.$letters;
        } while (Aircraft::query()->where('world_id', $world->id)->where('registration', $registration)->exists());

        return $registration;
    }
}
