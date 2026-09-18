<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Flight;
use App\Models\FlightFareEvent;
use App\Models\World;
use App\Services\Commercial\MarketCompetitionService;
use App\Services\Commercial\RevenueManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RevenueManagementController extends Controller
{
    public function __construct(
        private readonly RevenueManagementService $revenueManagement,
        private readonly MarketCompetitionService $competition,
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        $simulationNow = $world->simulated_at ?? now();

        $routes = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        $routeData = $routes->mapWithKeys(fn (AirlineRoute $route): array => [
            $route->id => [
                'fares' => $this->revenueManagement->routeFares($route, $airline->business_model),
                'policy' => $this->revenueManagement->routePricingPolicy($route),
            ],
        ]);

        $flights = Flight::query()
            ->with(['route.origin', 'route.destination', 'aircraft.type'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('scheduled_departure_at', '>=', $simulationNow)
            ->orderBy('scheduled_departure_at')
            ->limit(50)
            ->get();

        foreach ($flights as $flight) {
            $this->revenueManagement->initializeFlightPricing($flight);
            $flight->refresh();
        }

        $events = FlightFareEvent::query()
            ->with(['flight.route.origin', 'flight.route.destination'])
            ->where('airline_id', $airline->id)
            ->orderByDesc('calculated_at')
            ->limit(80)
            ->get();

        $dynamicRoutes = $routes->filter(
            fn (AirlineRoute $route): bool => $this->revenueManagement->routePricingPolicy($route)['mode'] === 'dynamic'
        )->count();

        $changedFlights = $flights->filter(function (Flight $flight): bool {
            foreach (['economy', 'business', 'first'] as $cabin) {
                $base = (int) data_get($flight->operational_data, 'commercial.cabins.'.$cabin.'.base_fare_minor', 0);
                $current = (int) data_get($flight->operational_data, 'commercial.cabins.'.$cabin.'.fare_minor', 0);
                if ($base > 0 && $current !== $base) {
                    return true;
                }
            }

            return false;
        })->count();

        return view('revenue-management.index', [
            'world' => $world,
            'airline' => $airline,
            'routes' => $routes,
            'routeData' => $routeData,
            'flights' => $flights,
            'events' => $events,
            'strategies' => (array) config('revenue_management.strategies', []),
            'dynamicRoutes' => $dynamicRoutes,
            'changedFlights' => $changedFlights,
            'eventsToday' => $events->filter(
                fn (FlightFareEvent $event): bool => $event->calculated_at?->greaterThanOrEqualTo($simulationNow->copy()->subDay()) ?? false
            )->count(),
        ]);
    }

    public function updateBaseFares(Request $request, AirlineRoute $route): RedirectResponse
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

        return redirect()->route('revenue-management.index')->with(
            'success',
            'Basistarife für '.$route->origin?->iata_code.' → '.$route->destination?->iata_code.' wurden gespeichert.'
        );
    }

    public function updatePolicy(Request $request, AirlineRoute $route): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($route->world_id === $world->id && $route->airline_id === $airline->id, 404);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['dynamic', 'manual'])],
            'strategy' => ['required', Rule::in(array_keys((array) config('revenue_management.strategies', [])))],
            'floor_percent' => ['required', 'integer', 'min:40', 'max:100'],
            'ceiling_percent' => ['required', 'integer', 'min:100', 'max:350'],
        ]);

        if ((int) $validated['ceiling_percent'] <= (int) $validated['floor_percent']) {
            throw ValidationException::withMessages([
                'ceiling_percent' => 'Die Preisobergrenze muss oberhalb der Preisuntergrenze liegen.',
            ]);
        }

        $this->revenueManagement->updateRoutePricingPolicy(
            $route,
            $validated['mode'],
            $validated['strategy'],
            (int) $validated['floor_percent'],
            (int) $validated['ceiling_percent'],
        );

        Flight::query()
            ->where('route_id', $route->id)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('scheduled_departure_at', '>=', $world->simulated_at ?? now())
            ->orderBy('scheduled_departure_at')
            ->each(fn (Flight $flight) => $this->revenueManagement->initializeFlightPricing($flight));

        return redirect()->route('revenue-management.index')->with(
            'success',
            'Revenue-Management-Policy für '.$route->origin?->iata_code.' → '.$route->destination?->iata_code.' wurde gespeichert.'
        );
    }

    public function reprice(Request $request, Flight $flight): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($flight->world_id === $world->id && $flight->airline_id === $airline->id, 404);

        if (! in_array($flight->status, ['scheduled', 'boarding'], true)) {
            throw ValidationException::withMessages([
                'flight' => 'Nur zukünftige oder im Boarding befindliche Flüge können neu bepreist werden.',
            ]);
        }

        $simulationNow = $world->simulated_at ?? now();
        $competition = $this->competition->snapshotForFlight($flight, $simulationNow);
        $result = $this->revenueManagement->repriceFlight($flight, $simulationNow, $competition, true);

        return redirect()->route('revenue-management.index')->with(
            'success',
            $result['changed']
                ? 'Tarife für '.$flight->flight_number.' wurden neu kalkuliert.'
                : 'Tarife für '.$flight->flight_number.' sind bereits optimal innerhalb der aktuellen Policy.'
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
}
