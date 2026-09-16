<?php

namespace App\Http\Middleware;

use App\Models\Aircraft;
use App\Models\AirlineRoute;
use App\Services\Operations\AircraftPositionService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAircraftAtRouteOrigin
{
    public function __construct(private readonly AircraftPositionService $positions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->filled(['route_id', 'aircraft_id', 'scheduled_departure_at'])) {
            return $next($request);
        }

        $worldId = $request->session()->get('active_world_id');
        if (! $worldId) {
            return $next($request);
        }

        $route = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->where('id', $request->string('route_id')->toString())
            ->where('world_id', $worldId)
            ->first();

        $aircraft = Aircraft::query()
            ->with(['type', 'currentAirport'])
            ->where('id', $request->string('aircraft_id')->toString())
            ->where('world_id', $worldId)
            ->first();

        if (! $route || ! $aircraft || $route->airline_id !== $aircraft->airline_id) {
            return $next($request);
        }

        try {
            $departure = Carbon::parse($request->string('scheduled_departure_at')->toString());
        } catch (\Throwable) {
            return $next($request);
        }

        $arrival = $departure->copy()->addMinutes((int) $route->planned_block_minutes);
        $this->positions->assertCanScheduleLeg($aircraft, $route, $departure, $arrival);

        return $next($request);
    }
}
