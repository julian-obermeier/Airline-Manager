<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\AirlineAirportStation;
use App\Models\Airport;
use App\Models\AirportSlotReservation;
use App\Models\World;
use App\Services\Operations\AirportOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AirportOperationsController extends Controller
{
    public function __construct(private readonly AirportOperationsService $airportOperations)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        $simulationNow = $world->simulated_at ?? now();

        $this->airportOperations->ensureNetworkStations($airline);
        $this->airportOperations->reserveMissingForAirline($airline, $simulationNow);

        $stations = AirlineAirportStation::query()
            ->with('airport')
            ->where('airline_id', $airline->id)
            ->orderByRaw("FIELD(station_type, 'base', 'outstation')")
            ->orderBy('opened_at')
            ->get();

        $slots = AirportSlotReservation::query()
            ->with(['airport', 'flight.route.origin', 'flight.route.destination'])
            ->where('airline_id', $airline->id)
            ->where('scheduled_at', '>=', $simulationNow)
            ->whereIn('status', ['reserved', 'used'])
            ->orderBy('scheduled_at')
            ->limit(80)
            ->get();

        $airportIds = $stations->pluck('airport_id');

        return view('airport-operations.index', [
            'world' => $world,
            'airline' => $airline,
            'stations' => $stations,
            'slots' => $slots,
            'airports' => Airport::query()
                ->when($airportIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $airportIds))
                ->orderBy('country_code')
                ->orderBy('city')
                ->get(),
            'monthlyStationCostMinor' => (int) $stations->where('status', 'active')->sum('monthly_cost_minor'),
            'upcomingSlotCount' => $slots->where('status', 'reserved')->count(),
            'baseCount' => $stations->where('station_type', 'base')->where('status', 'active')->count(),
            'outstationCount' => $stations->where('station_type', 'outstation')->where('status', 'active')->count(),
        ]);
    }

    public function storeStation(Request $request): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $validated = $request->validate([
            'airport_id' => ['required', 'string', 'exists:airports,id'],
        ]);

        $airport = Airport::findOrFail($validated['airport_id']);

        $existing = AirlineAirportStation::query()
            ->where('airline_id', $airline->id)
            ->where('airport_id', $airport->id)
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'airport_id' => 'An diesem Flughafen besteht bereits eine aktive Station.',
            ]);
        }

        $station = $this->airportOperations->ensureStation(
            $airline,
            $airport,
            $airport->id === $airline->home_airport_id ? 'base' : 'outstation'
        );

        return redirect()->route('airport-operations.index')->with(
            'success',
            'Station '.$airport->iata_code.' wurde eröffnet. Monatliche Kosten: '.
            number_format($station->monthly_cost_minor / 100, 2, ',', '.').' '.$station->currency.'.'
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
            ->with(['world', 'homeAirport', 'routes.origin', 'routes.destination'])
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
