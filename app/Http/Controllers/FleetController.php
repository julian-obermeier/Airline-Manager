<?php

namespace App\Http\Controllers;

use App\Models\Aircraft;
use App\Models\AircraftProcurement;
use App\Models\Airline;
use App\Models\World;
use App\Services\Operations\FleetManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FleetController extends Controller
{
    public function __construct(private readonly FleetManagementService $fleetManagement)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);
        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        $asOf = $world->simulated_at ?? now();

        $fleet = Aircraft::query()
            ->with(['type', 'currentAirport'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', '!=', 'sold')
            ->orderBy('registration')
            ->get();

        $procurements = AircraftProcurement::query()
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->whereIn('delivered_aircraft_id', $fleet->pluck('id'))
            ->get()
            ->keyBy('delivered_aircraft_id');

        $fleetRows = $fleet->map(function (Aircraft $aircraft) use ($asOf, $procurements): array {
            return [
                'aircraft' => $aircraft,
                'procurement' => $procurements->get($aircraft->id),
                'estimated_sale_minor' => $this->fleetManagement->estimatedSaleValueMinor($aircraft, $asOf),
                'sale_block_reason' => $this->fleetManagement->saleBlockReason($aircraft, $asOf),
            ];
        });

        return view('fleet.index', [
            'world' => $world,
            'airline' => $airline,
            'fleetRows' => $fleetRows,
            'ownedCount' => $fleet->where('ownership_type', 'owned')->count(),
            'leasedCount' => $fleet->where('ownership_type', 'leased')->count(),
            'availableCount' => $fleet->where('status', 'available')->count(),
            'estimatedFleetValueMinor' => (int) $fleetRows
                ->filter(fn (array $row): bool => $row['aircraft']->ownership_type === 'owned')
                ->sum('estimated_sale_minor'),
        ]);
    }

    public function sell(Request $request, Aircraft $aircraft): RedirectResponse
    {
        $context = $this->activeContext($request);
        abort_unless($context, 404);
        [$world, $airline] = $context;

        abort_unless($aircraft->world_id === $world->id && $aircraft->airline_id === $airline->id, 404);

        $saleValue = $this->fleetManagement->sellOwnedAircraft(
            $airline,
            $aircraft,
            $world->simulated_at ?? now()
        );

        return redirect()->route('fleet.index')->with(
            'success',
            $aircraft->registration.' wurde für '.number_format($saleValue / 100, 2, ',', '.').' '.$airline->base_currency.' verkauft.'
        );
    }

    private function activeContext(Request $request): ?array
    {
        $worldId = $request->session()->get('active_world_id');
        if (! $worldId) return null;

        $membershipExists = DB::table('world_memberships')
            ->where('world_id', $worldId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        if (! $membershipExists) return null;

        $world = World::find($worldId);
        $airline = Airline::query()
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
