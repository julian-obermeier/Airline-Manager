<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\World;
use App\Services\Commercial\MarketCompetitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MarketController extends Controller
{
    public function __construct(private readonly MarketCompetitionService $competition)
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

        $routes = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        $snapshots = $routes->mapWithKeys(function (AirlineRoute $route) use ($simulationNow): array {
            return [$route->id => $this->competition->marketSnapshot($route, $simulationNow)];
        });

        $allCompetitors = $snapshots
            ->flatMap(fn (array $snapshot): array => $snapshot['participants'] ?? [])
            ->filter(fn (array $participant): bool => ($participant['airline_id'] ?? null) !== $airline->id)
            ->unique('airline_id')
            ->values();

        $shares = $snapshots
            ->pluck('market_share')
            ->filter(fn ($share): bool => is_numeric($share));

        return view('market.index', [
            'world' => $world,
            'airline' => $airline,
            'routes' => $routes,
            'snapshots' => $snapshots,
            'marketCount' => $routes->count(),
            'contestedCount' => $snapshots->filter(fn (array $snapshot): bool => ($snapshot['competitor_count'] ?? 0) > 0)->count(),
            'competitorCount' => $allCompetitors->count(),
            'averageShare' => $shares->isEmpty() ? 1.0 : (float) $shares->avg(),
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
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
