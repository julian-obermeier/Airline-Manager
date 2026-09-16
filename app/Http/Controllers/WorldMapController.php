<?php

namespace App\Http\Controllers;

use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\World;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorldMapController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $context = $this->activeContext($request);

        if (! $context) {
            return redirect()->route('home');
        }

        [$world, $airline] = $context;
        $simulationNow = ($world->simulated_at ?? now())->copy();

        $airports = Airport::query()
            ->orderBy('country_code')
            ->orderBy('city')
            ->get();

        $routes = AirlineRoute::query()
            ->with(['origin', 'destination'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();

        $liveFlights = Flight::query()
            ->with(['route.origin', 'route.destination', 'aircraft.type'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->whereIn('status', ['boarding', 'departed', 'in_air'])
            ->orderBy('scheduled_departure_at')
            ->get();

        $networkAirportIds = collect([$airline->home_airport_id])
            ->merge($routes->pluck('origin_airport_id'))
            ->merge($routes->pluck('destination_airport_id'))
            ->filter()
            ->unique()
            ->values();

        $bounds = $this->bounds($airports);

        $mapAirports = $airports->map(function (Airport $airport) use ($bounds, $networkAirportIds, $airline): array {
            [$x, $y] = $this->project((float) $airport->latitude, (float) $airport->longitude, $bounds);

            return [
                'id' => $airport->id,
                'iata' => $airport->iata_code ?: $airport->icao_code,
                'icao' => $airport->icao_code,
                'name' => $airport->name,
                'city' => $airport->city,
                'country_code' => $airport->country_code,
                'x' => $x,
                'y' => $y,
                'network' => $networkAirportIds->contains($airport->id),
                'home' => $airline->home_airport_id === $airport->id,
            ];
        })->keyBy('id');

        $mapRoutes = $routes->map(function (AirlineRoute $route) use ($mapAirports): ?array {
            $origin = $mapAirports->get($route->origin_airport_id);
            $destination = $mapAirports->get($route->destination_airport_id);

            if (! $origin || ! $destination) {
                return null;
            }

            return [
                'id' => $route->id,
                'origin' => $origin,
                'destination' => $destination,
                'distance_km' => (float) $route->distance_km,
                'block_minutes' => (int) $route->planned_block_minutes,
            ];
        })->filter()->values();

        $mapFlights = $liveFlights->map(function (Flight $flight) use ($simulationNow, $mapAirports): ?array {
            $route = $flight->route;
            if (! $route) {
                return null;
            }

            $origin = $mapAirports->get($route->origin_airport_id);
            $destination = $mapAirports->get($route->destination_airport_id);
            if (! $origin || ! $destination) {
                return null;
            }

            $delay = max(0, (int) $flight->delay_minutes);
            $departure = $flight->scheduled_departure_at->copy()->addMinutes($delay);
            $arrival = $flight->scheduled_arrival_at->copy()->addMinutes($delay);

            if ($flight->status === 'boarding' || $simulationNow->lessThanOrEqualTo($departure)) {
                $progress = 0.0;
            } elseif ($simulationNow->greaterThanOrEqualTo($arrival)) {
                $progress = 1.0;
            } else {
                $totalSeconds = max(1, $departure->diffInSeconds($arrival));
                $elapsedSeconds = max(0, $departure->diffInSeconds($simulationNow));
                $progress = min(1, max(0, $elapsedSeconds / $totalSeconds));
            }

            $x = $origin['x'] + (($destination['x'] - $origin['x']) * $progress);
            $y = $origin['y'] + (($destination['y'] - $origin['y']) * $progress);

            return [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'status' => $flight->status,
                'delay_minutes' => $delay,
                'aircraft' => $flight->aircraft?->registration,
                'origin' => $origin['iata'],
                'destination' => $destination['iata'],
                'progress_percent' => (int) round($progress * 100),
                'x' => round($x, 2),
                'y' => round($y, 2),
            ];
        })->filter()->values();

        return view('map.index', [
            'world' => $world,
            'airline' => $airline,
            'simulationNow' => $simulationNow,
            'mapAirports' => $mapAirports->values(),
            'mapRoutes' => $mapRoutes,
            'mapFlights' => $mapFlights,
            'routes' => $routes,
        ]);
    }

    private function bounds($airports): array
    {
        if ($airports->isEmpty()) {
            return ['min_lat' => -60.0, 'max_lat' => 80.0, 'min_lon' => -180.0, 'max_lon' => 180.0];
        }

        $minLat = (float) $airports->min('latitude');
        $maxLat = (float) $airports->max('latitude');
        $minLon = (float) $airports->min('longitude');
        $maxLon = (float) $airports->max('longitude');

        $latSpan = max(4.0, $maxLat - $minLat);
        $lonSpan = max(6.0, $maxLon - $minLon);
        $latMargin = $latSpan * 0.14;
        $lonMargin = $lonSpan * 0.14;

        return [
            'min_lat' => max(-85.0, $minLat - $latMargin),
            'max_lat' => min(85.0, $maxLat + $latMargin),
            'min_lon' => max(-180.0, $minLon - $lonMargin),
            'max_lon' => min(180.0, $maxLon + $lonMargin),
        ];
    }

    private function project(float $latitude, float $longitude, array $bounds): array
    {
        $width = 1000.0;
        $height = 600.0;
        $paddingX = 55.0;
        $paddingY = 45.0;
        $usableWidth = $width - ($paddingX * 2);
        $usableHeight = $height - ($paddingY * 2);

        $lonSpan = max(0.000001, $bounds['max_lon'] - $bounds['min_lon']);
        $latSpan = max(0.000001, $bounds['max_lat'] - $bounds['min_lat']);

        $x = $paddingX + ((($longitude - $bounds['min_lon']) / $lonSpan) * $usableWidth);
        $y = $paddingY + (((($bounds['max_lat'] - $latitude) / $latSpan)) * $usableHeight);

        return [round($x, 2), round($y, 2)];
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
