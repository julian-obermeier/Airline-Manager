<?php

namespace App\Http\Controllers;

use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\CrewQualification;
use App\Models\Flight;
use App\Models\FlightCrewAssignment;
use App\Models\World;
use App\Services\Operations\CrewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CrewController extends Controller
{
    public function __construct(private readonly CrewService $crewService)
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

        $crew = CrewMember::query()
            ->with([
                'homeAirport',
                'currentAirport',
                'qualifications.aircraftType',
            ])
            ->withCount([
                'flightAssignments as upcoming_assignments_count' => fn ($query) => $query
                    ->where('status', 'assigned')
                    ->where('duty_start_at', '>=', $simulationNow),
            ])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderByRaw("FIELD(status, 'active', 'inactive')")
            ->orderBy('role')
            ->orderBy('employee_number')
            ->get();

        $upcomingFlights = Flight::query()
            ->with([
                'route.origin',
                'route.destination',
                'aircraft.type',
                'crewAssignments.crewMember',
            ])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('scheduled_departure_at', '>=', $simulationNow)
            ->orderBy('scheduled_departure_at')
            ->limit(40)
            ->get();

        $staffing = $upcomingFlights->mapWithKeys(fn (Flight $flight): array => [
            $flight->id => $this->crewService->staffingSnapshot($flight),
        ]);

        $activeCrew = $crew->where('status', 'active');

        return view('crew.index', [
            'world' => $world,
            'airline' => $airline,
            'crew' => $crew,
            'aircraftTypes' => AircraftType::query()->orderBy('manufacturer')->orderBy('model')->get(),
            'airports' => Airport::query()->orderBy('country_code')->orderBy('city')->get(),
            'upcomingFlights' => $upcomingFlights,
            'staffing' => $staffing,
            'activeCount' => $activeCrew->count(),
            'captainCount' => $activeCrew->where('role', 'captain')->count(),
            'firstOfficerCount' => $activeCrew->where('role', 'first_officer')->count(),
            'cabinCrewCount' => $activeCrew->where('role', 'cabin_crew')->count(),
            'monthlyPayrollMinor' => (int) $activeCrew->sum('monthly_salary_minor'),
            'understaffedFlights' => $staffing->filter(fn (array $snapshot): bool => ! $snapshot['complete'])->count(),
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
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['required', 'string', 'min:2', 'max:80'],
            'role' => ['required', Rule::in(['captain', 'first_officer', 'cabin_crew'])],
            'base_airport_id' => ['required', 'string', 'exists:airports,id'],
            'monthly_salary' => ['required', 'numeric', 'min:1000', 'max:50000'],
            'aircraft_type_id' => ['nullable', 'string', 'exists:aircraft_types,id'],
        ]);

        if (in_array($validated['role'], ['captain', 'first_officer'], true)
            && empty($validated['aircraft_type_id'])) {
            throw ValidationException::withMessages([
                'aircraft_type_id' => 'Für Piloten muss bei der Einstellung mindestens ein Type Rating ausgewählt werden.',
            ]);
        }

        $hiredAt = $world->simulated_at ?? now();
        $employeeNumber = $this->nextEmployeeNumber($airline);

        DB::transaction(function () use ($validated, $world, $airline, $hiredAt, $employeeNumber): void {
            $member = CrewMember::create([
                'world_id' => $world->id,
                'airline_id' => $airline->id,
                'home_airport_id' => $validated['base_airport_id'],
                'current_airport_id' => $validated['base_airport_id'],
                'employee_number' => $employeeNumber,
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'role' => $validated['role'],
                'status' => 'active',
                'monthly_salary_minor' => (int) round(((float) $validated['monthly_salary']) * 100),
                'currency' => $airline->base_currency,
                'hired_at' => $hiredAt,
                'max_duty_minutes_day' => (int) config('crew.default_max_duty_minutes_day', 780),
                'min_rest_minutes' => (int) config('crew.default_min_rest_minutes', 660),
                'metadata' => [
                    'source' => 'manual_recruitment',
                    'simulation_rules' => 'crew_v1',
                ],
            ]);

            if (! empty($validated['aircraft_type_id'])) {
                CrewQualification::create([
                    'crew_member_id' => $member->id,
                    'aircraft_type_id' => $validated['aircraft_type_id'],
                    'qualification_type' => 'type_rating',
                    'valid_from' => $hiredAt->toDateString(),
                    'status' => 'active',
                    'metadata' => ['source' => 'initial_hire'],
                ]);
            }
        });

        $this->crewService->assignUpcomingForAirline($airline, $hiredAt);

        return redirect()->route('crew.index')->with(
            'success',
            $employeeNumber.' wurde eingestellt. Offene Flüge wurden automatisch neu auf Crew geprüft.'
        );
    }

    public function storeQualification(Request $request, CrewMember $crewMember): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($crewMember->world_id === $world->id && $crewMember->airline_id === $airline->id, 404);

        $validated = $request->validate([
            'aircraft_type_id' => ['required', 'string', 'exists:aircraft_types,id'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        CrewQualification::query()->updateOrCreate(
            [
                'crew_member_id' => $crewMember->id,
                'aircraft_type_id' => $validated['aircraft_type_id'],
                'qualification_type' => 'type_rating',
            ],
            [
                'valid_from' => ($world->simulated_at ?? now())->toDateString(),
                'valid_until' => $validated['valid_until'] ?? null,
                'status' => 'active',
                'metadata' => ['source' => 'crew_management'],
            ]
        );

        $this->crewService->assignUpcomingForAirline($airline, $world->simulated_at ?? now());

        return redirect()->route('crew.index')->with('success', 'Type Rating wurde gespeichert.');
    }

    public function terminate(Request $request, CrewMember $crewMember): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($crewMember->world_id === $world->id && $crewMember->airline_id === $airline->id, 404);

        $onActiveFlight = FlightCrewAssignment::query()
            ->where('crew_member_id', $crewMember->id)
            ->where('status', 'assigned')
            ->whereHas('flight', fn ($query) => $query->whereIn('status', ['departed', 'in_air']))
            ->exists();

        if ($onActiveFlight) {
            throw ValidationException::withMessages([
                'crew' => 'Dieser Mitarbeiter befindet sich aktuell auf einem laufenden Flug und kann erst nach der Landung beendet werden.',
            ]);
        }

        $affectedFlightIds = FlightCrewAssignment::query()
            ->where('crew_member_id', $crewMember->id)
            ->where('status', 'assigned')
            ->pluck('flight_id');

        DB::transaction(function () use ($crewMember, $world): void {
            $crewMember->forceFill([
                'status' => 'inactive',
                'terminated_at' => $world->simulated_at ?? now(),
            ])->save();

            FlightCrewAssignment::query()
                ->where('crew_member_id', $crewMember->id)
                ->where('status', 'assigned')
                ->update(['status' => 'released']);
        });

        Flight::query()
            ->whereIn('id', $affectedFlightIds)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->orderBy('scheduled_departure_at')
            ->each(fn (Flight $flight) => $this->crewService->assignCrew($flight));

        return redirect()->route('crew.index')->with('success', 'Mitarbeiter wurde aus dem aktiven Personalbestand genommen.');
    }

    private function nextEmployeeNumber(Airline $airline): string
    {
        $number = CrewMember::query()->where('airline_id', $airline->id)->count() + 1;

        do {
            $candidate = 'EMP'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $number++;
        } while (CrewMember::query()
            ->where('airline_id', $airline->id)
            ->where('employee_number', $candidate)
            ->exists());

        return $candidate;
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
            ->with('world')
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
