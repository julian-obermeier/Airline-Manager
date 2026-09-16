<?php

namespace App\Services\Commercial;

use App\Models\AirlineRoute;
use App\Models\Airport;

class RevenueManagementService
{
    public function defaultFares(float $distanceKm, string $businessModel): array
    {
        $modelMultiplier = match ($businessModel) {
            'low_cost' => 0.78,
            'full_service' => 1.16,
            'regional' => 1.05,
            'cargo' => 0.85,
            default => 1.00,
        };

        $economyMinor = (int) round(max(4900, 3900 + ($distanceKm * 12)) * $modelMultiplier);

        return [
            'economy_minor' => $economyMinor,
            'business_minor' => (int) round($economyMinor * 2.15),
            'first_minor' => (int) round($economyMinor * 3.65),
        ];
    }

    public function routeFares(AirlineRoute $route, string $businessModel): array
    {
        $defaults = $this->defaultFares((float) $route->distance_km, $businessModel);

        return [
            'economy_minor' => max(0, (int) data_get($route->settings, 'fares.economy_minor', $defaults['economy_minor'])),
            'business_minor' => max(0, (int) data_get($route->settings, 'fares.business_minor', $defaults['business_minor'])),
            'first_minor' => max(0, (int) data_get($route->settings, 'fares.first_minor', $defaults['first_minor'])),
        ];
    }

    public function cabinLayout(int $totalSeats, string $businessModel): array
    {
        $totalSeats = max(1, $totalSeats);

        [$businessRatio, $firstRatio] = match ($businessModel) {
            'low_cost' => [0.04, 0.00],
            'full_service' => [0.18, 0.04],
            'regional' => [0.10, 0.00],
            'cargo' => [0.00, 0.00],
            default => [0.12, 0.02],
        };

        $first = (int) floor($totalSeats * $firstRatio);
        $business = (int) floor($totalSeats * $businessRatio);
        $economy = max(0, $totalSeats - $business - $first);

        return [
            'economy' => $economy,
            'business' => $business,
            'first' => $first,
        ];
    }

    public function demandIndex(Airport $origin, Airport $destination): float
    {
        $pair = [$origin->icao_code, $destination->icao_code];
        sort($pair);
        $unsigned = (int) sprintf('%u', crc32(implode(':', $pair)));

        return round(0.85 + (($unsigned % 36) / 100), 2);
    }
}
