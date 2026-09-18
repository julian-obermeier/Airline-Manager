<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\Airport;
use App\Models\World;
use App\Services\Planning\RoutePlannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoutePlannerController extends Controller
{
    public function __construct(private readonly RoutePlannerService $planner)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);
        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;

        $airports = Airport::query()
            ->orderBy('country_code')
            ->orderBy('city')
            ->get();

        $originId = $request->string('origin')->toString() ?: $airline->home_airport_id;
        $destinationId = $request->string('destination')->toString();

        $origin = $airports->firstWhere('id', $originId) ?: $airline->homeAirport;
        $destination = $destinationId ? $airports->firstWhere('id', $destinationId) : null;

        if ($destination && $origin && $destination->id === $origin->id) {
            $destination = null;
        }

        $analysis = ($origin && $destination)
            ? $this->planner->analyse($airline, $world, $origin, $destination)
            : null;

        $opportunities = $origin
            ? $this->planner->opportunities($airline, $world, $origin, $airports)
            : collect();

        return view('route-planner.index', [
            'world' => $world,
            'airline' => $airline,
            'airports' => $airports,
            'origin' => $origin,
            'destination' => $destination,
            'analysis' => $analysis,
            'opportunities' => $opportunities,
        ]);
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
            ->with('homeAirport')
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
