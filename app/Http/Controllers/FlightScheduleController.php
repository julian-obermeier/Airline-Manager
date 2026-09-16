<?php

namespace App\Http\Controllers;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\FlightSchedule;
use App\Models\World;
use App\Services\Operations\FlightScheduleService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FlightScheduleController extends Controller
{
    public function __construct(private readonly FlightScheduleService $schedules)
    {
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
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        $flightSchedules = FlightSchedule::query()
            ->with([
                'aircraft.type',
                'outboundRoute.origin',
                'outboundRoute.destination',
                'returnRoute.origin',
                'returnRoute.destination',
            ])
            ->withCount([
                'flights as future_flights_count' => fn ($query) => $query
                    ->whereNotIn('status', ['cancelled', 'completed'])
                    ->where('scheduled_departure_at', '>=', now()),
            ])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderByDesc('created_at')
            ->get();

        return view('schedules.index', [
            'world' => $world,
            'airline' => $airline,
            'fleet' => $fleet,
            'routes' => $routes,
            'flightSchedules' => $flightSchedules,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $validated = $request->validate([
            'aircraft_id' => ['required', 'string', 'exists:aircraft,id'],
            'outbound_route_id' => ['required', 'string', 'exists:routes,id'],
            'return_route_id' => ['required', 'string', 'different:outbound_route_id', 'exists:routes,id'],
            'outbound_flight_number' => ['required', 'string', 'min:2', 'max:12', 'regex:/^[A-Za-z0-9 -]+$/'],
            'return_flight_number' => ['required', 'string', 'min:2', 'max:12', 'regex:/^[A-Za-z0-9 -]+$/'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'departure_time' => ['required', 'date_format:H:i'],
            'turnaround_minutes' => ['required', 'integer', 'between:20,240'],
        ]);

        $aircraft = Aircraft::query()
            ->with('type')
            ->where('id', $validated['aircraft_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->firstOrFail();

        $outbound = AirlineRoute::query()
            ->where('id', $validated['outbound_route_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->firstOrFail();

        $return = AirlineRoute::query()
            ->where('id', $validated['return_route_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->firstOrFail();

        if ($outbound->destination_airport_id !== $return->origin_airport_id
            || $outbound->origin_airport_id !== $return->destination_airport_id) {
            throw ValidationException::withMessages([
                'return_route_id' => 'Die Rückroute muss exakt zum Ausgangsflughafen der Hinroute zurückführen.',
            ]);
        }

        foreach ([$outbound, $return] as $route) {
            if ($aircraft->type?->range_km && (float) $route->distance_km > (float) $aircraft->type->range_km) {
                throw ValidationException::withMessages([
                    'aircraft_id' => 'Die Reichweite des gewählten Flugzeugs reicht für den vollständigen Umlauf nicht aus.',
                ]);
            }
        }

        $minimumTurnaround = $this->schedules->minimumTurnaroundMinutes($aircraft);

        if ((int) $validated['turnaround_minutes'] < $minimumTurnaround) {
            throw ValidationException::withMessages([
                'turnaround_minutes' => 'Für dieses Flugzeug sind mindestens '.$minimumTurnaround.' Minuten Turnaround erforderlich.',
            ]);
        }

        $schedule = FlightSchedule::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'aircraft_id' => $aircraft->id,
            'outbound_route_id' => $outbound->id,
            'return_route_id' => $return->id,
            'outbound_flight_number' => strtoupper(trim($validated['outbound_flight_number'])),
            'return_flight_number' => strtoupper(trim($validated['return_flight_number'])),
            'days_of_week' => array_values(array_unique(array_map('intval', $validated['days_of_week']))),
            'starts_on' => $validated['starts_on'],
            'departure_time' => $validated['departure_time'],
            'turnaround_minutes' => (int) $validated['turnaround_minutes'],
            'generation_horizon_days' => 28,
            'status' => 'active',
            'settings' => [
                'minimum_turnaround_minutes' => $minimumTurnaround,
                'created_from' => 'operations_ui',
            ],
        ]);

        $result = $this->schedules->generate($schedule, now());

        return redirect()->route('schedules.index')->with(
            'success',
            'Flugplan aktiviert. '.$result['flights_created'].' konkrete Flüge wurden erzeugt'.
            ($result['rotations_skipped'] > 0 ? '; '.$result['rotations_skipped'].' Umläufe wurden wegen Konflikten übersprungen.' : '.')
        );
    }

    public function toggle(Request $request, FlightSchedule $schedule): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($schedule->world_id === $world->id && $schedule->airline_id === $airline->id, 404);

        $newStatus = $schedule->status === 'active' ? 'paused' : 'active';
        $schedule->forceFill(['status' => $newStatus])->save();

        if ($newStatus === 'active') {
            $this->schedules->generate($schedule, now());
        }

        return redirect()->route('schedules.index')->with(
            'success',
            $newStatus === 'active' ? 'Flugplan wurde aktiviert.' : 'Flugplan wurde pausiert. Bereits erzeugte Flüge bleiben bestehen.'
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
