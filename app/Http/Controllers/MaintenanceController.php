<?php

namespace App\Http\Controllers;

use App\Models\Aircraft;
use App\Models\AircraftMaintenanceEvent;
use App\Models\Airline;
use App\Models\World;
use App\Services\Operations\MaintenanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance)
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

        $snapshots = $fleet->mapWithKeys(fn (Aircraft $aircraft): array => [
            $aircraft->id => $this->maintenance->snapshot($aircraft),
        ]);

        $events = AircraftMaintenanceEvent::query()
            ->with(['aircraft.type'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->orderByRaw("FIELD(status, 'in_progress', 'planned', 'completed', 'cancelled')")
            ->orderByDesc('planned_start_at')
            ->limit(40)
            ->get();

        return view('maintenance.index', [
            'world' => $world,
            'airline' => $airline,
            'fleet' => $fleet,
            'snapshots' => $snapshots,
            'events' => $events,
            'cashBalanceMinor' => $this->maintenance->cashBalanceMinor($airline),
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
            'check_type' => ['required', Rule::in(['a_check', 'c_check', 'repair'])],
            'planned_start_at' => ['required', 'date', 'after:now'],
        ]);

        $aircraft = Aircraft::query()
            ->with('type')
            ->where('id', $validated['aircraft_id'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->firstOrFail();

        $quote = $this->maintenance->quote($aircraft, $validated['check_type']);

        if ($this->maintenance->cashBalanceMinor($airline) < $quote['cost_minor']) {
            throw ValidationException::withMessages([
                'check_type' => 'Die aktuelle Liquidität reicht für diese Wartung nicht aus.',
            ]);
        }

        try {
            $event = $this->maintenance->schedule(
                $aircraft,
                $airline,
                $validated['check_type'],
                Carbon::parse($validated['planned_start_at'])
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'planned_start_at' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('maintenance.index')->with(
            'success',
            'Wartung geplant: '.config('maintenance.checks.'.$event->check_type.'.label').
            ' für '.$aircraft->registration.' · '.number_format($event->cost_minor / 100, 2, ',', '.').' '.$event->currency.'.'
        );
    }

    public function cancel(Request $request, AircraftMaintenanceEvent $event): RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($event->world_id === $world->id && $event->airline_id === $airline->id, 404);

        if ($event->status !== 'planned') {
            throw ValidationException::withMessages([
                'maintenance' => 'Nur noch nicht begonnene Wartungsereignisse können storniert werden.',
            ]);
        }

        $event->forceFill(['status' => 'cancelled'])->save();

        return redirect()->route('maintenance.index')->with('success', 'Geplantes Wartungsfenster wurde storniert.');
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
