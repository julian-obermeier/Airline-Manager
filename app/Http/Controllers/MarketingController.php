<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\MarketingCampaign;
use App\Models\World;
use App\Services\Commercial\MarketingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function __construct(private readonly MarketingService $marketing)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);
        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        $profile = $this->marketing->ensureProfile($airline);

        $routes = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->where('airline_id', $airline->id)
            ->orderBy('created_at')
            ->get();

        $routeMetrics = $routes->mapWithKeys(function (AirlineRoute $route): array {
            return [$route->id => $this->marketing->ensureRouteMetric($route)];
        });

        $campaigns = MarketingCampaign::query()
            ->with('route.origin', 'route.destination')
            ->where('airline_id', $airline->id)
            ->orderByDesc('created_at')
            ->get();

        return view('marketing.index', [
            'world' => $world,
            'airline' => $airline,
            'profile' => $profile,
            'routes' => $routes,
            'routeMetrics' => $routeMetrics,
            'campaigns' => $campaigns,
            'channels' => (array) config('marketing.channels', []),
            'cashBalanceMinor' => $this->marketing->cashBalanceMinor($airline),
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
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'scope' => ['required', Rule::in(['brand', 'route'])],
            'route_id' => ['nullable', 'string', 'exists:routes,id'],
            'channel' => ['required', Rule::in(array_keys((array) config('marketing.channels', [])))],
            'budget' => ['required', 'numeric', 'min:10000', 'max:5000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $route = null;
        if ($validated['scope'] === 'route') {
            $route = AirlineRoute::query()
                ->where('id', $validated['route_id'] ?? '')
                ->where('world_id', $world->id)
                ->where('airline_id', $airline->id)
                ->firstOrFail();
        }

        $campaign = $this->marketing->launchCampaign(
            $airline,
            $validated['name'],
            $validated['scope'],
            $validated['channel'],
            (int) round(((float) $validated['budget']) * 100),
            (int) $validated['duration_days'],
            $route,
        );

        return redirect()->route('marketing.index')->with(
            'success',
            'Kampagne '.$campaign->name.' wurde gestartet. Budget: '.
            number_format($campaign->budget_minor / 100, 2, ',', '.').' '.$campaign->currency.'.'
        );
    }

    public function cancel(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $context = $this->activeContext($request);
        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        abort_unless($campaign->world_id === $world->id && $campaign->airline_id === $airline->id, 404);

        $this->marketing->cancelCampaign($campaign, $world->simulated_at ?? now());

        return redirect()->route('marketing.index')->with(
            'success',
            'Kampagne wurde beendet. Bereits eingesetztes Budget wird nicht erstattet.'
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
            ->with('world')
            ->where('world_id', $worldId)
            ->where('owner_user_id', $request->user()->id)
            ->first();

        return $world && $airline ? [$world, $airline] : null;
    }
}
