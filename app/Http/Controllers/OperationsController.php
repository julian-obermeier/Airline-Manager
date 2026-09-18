<?php

namespace App\Http\Controllers;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
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

        $crewSnapshots = $flights->mapWithKeys(fn (Flight $flight): array => [
            $flight->id => $this->crewService->staffingSnapshot($flight),
        ]);

        return view('operations.index', [
            'world' => $world,
            'airline' => $airline,
            'cashBalanceMinor' => $this->cashBalanceMinor($airline),
            'airports' => Airport::query()->orderBy('country_code')->orderBy('city')->get(),
            'fleet' => $fleet,
            'routes' => $routes,
            'flights' => $flights,
            'crewSnapshots' => $crewSnapshots,
        ]);
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
                'pricing_policy' => $this->revenueManagement->defaultPricingPolicy(),
                'demand_index' => $this->revenueManagement->demandIndex($origin, $destination),
            ],
        ]);

        $this->marketing->ensureRouteMetric($newRoute);

        return redirect()->route('operations.index')->with('success', 'Route wurde angelegt. Standardtarife, Marktnachfrage und Streckenbekanntheit wurden initialisiert.');
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
        $pricingPolicy = $this->revenueManagement->routePricingPolicy($route);

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
                    'pricing' => $pricingPolicy,
                    'cabins' => [
                        'economy' => [
                            'capacity' => $cabins['economy'],
                            'base_fare_minor' => $fares['economy_minor'],
                            'fare_minor' => $fares['economy_minor'],
                            'booked' => 0,
                            'revenue_minor' => 0,
                            'fare_bucket' => 'initial',
                        ],
                        'business' => [
                            'capacity' => $cabins['business'],
                            'base_fare_minor' => $fares['business_minor'],
                            'fare_minor' => $fares['business_minor'],
                            'booked' => 0,
                            'revenue_minor' => 0,
                            'fare_bucket' => 'initial',
                        ],
                        'first' => [
                            'capacity' => $cabins['first'],
                            'base_fare_minor' => $fares['first_minor'],
                            'fare_minor' => $fares['first_minor'],
                            'booked' => 0,
                            'revenue_minor' => 0,
                            'fare_bucket' => 'initial',
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

}
