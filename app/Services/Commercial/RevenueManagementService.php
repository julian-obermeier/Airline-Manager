<?php

namespace App\Services\Commercial;

use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightFareEvent;
use Carbon\Carbon;

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

    public function defaultPricingPolicy(): array
    {
        return [
            'mode' => (string) config('revenue_management.default_policy.mode', 'dynamic'),
            'strategy' => (string) config('revenue_management.default_policy.strategy', 'balanced'),
            'floor_percent' => (int) config('revenue_management.default_policy.floor_percent', 70),
            'ceiling_percent' => (int) config('revenue_management.default_policy.ceiling_percent', 220),
        ];
    }

    public function routePricingPolicy(AirlineRoute $route): array
    {
        $defaults = $this->defaultPricingPolicy();
        $policy = (array) data_get($route->settings, 'pricing_policy', []);

        $mode = in_array(($policy['mode'] ?? null), ['dynamic', 'manual'], true)
            ? $policy['mode']
            : $defaults['mode'];
        $strategies = array_keys((array) config('revenue_management.strategies', []));
        $strategy = in_array(($policy['strategy'] ?? null), $strategies, true)
            ? $policy['strategy']
            : $defaults['strategy'];

        return [
            'mode' => $mode,
            'strategy' => $strategy,
            'floor_percent' => max(40, min(100, (int) ($policy['floor_percent'] ?? $defaults['floor_percent']))),
            'ceiling_percent' => max(100, min(350, (int) ($policy['ceiling_percent'] ?? $defaults['ceiling_percent']))),
        ];
    }

    public function updateRoutePricingPolicy(
        AirlineRoute $route,
        string $mode,
        string $strategy,
        int $floorPercent,
        int $ceilingPercent,
    ): AirlineRoute {
        $settings = $route->settings ?? [];
        $settings['pricing_policy'] = [
            'mode' => in_array($mode, ['dynamic', 'manual'], true) ? $mode : 'dynamic',
            'strategy' => array_key_exists($strategy, (array) config('revenue_management.strategies', []))
                ? $strategy
                : 'balanced',
            'floor_percent' => max(40, min(100, $floorPercent)),
            'ceiling_percent' => max(100, min(350, $ceilingPercent)),
        ];
        $settings['pricing_policy_updated_at'] = now()->toIso8601String();

        $route->forceFill(['settings' => $settings])->save();

        return $route->fresh();
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

    public function initializeFlightPricing(Flight $flight): void
    {
        $flight->loadMissing(['route', 'airline']);
        if (! $flight->route || ! $flight->airline) {
            return;
        }

        $data = $flight->operational_data ?? [];
        $commercial = $data['commercial'] ?? [];
        $cabins = $commercial['cabins'] ?? [];
        $routeFares = $this->routeFares($flight->route, $flight->airline->business_model);
        $policy = $this->routePricingPolicy($flight->route);

        foreach (['economy', 'business', 'first'] as $cabin) {
            if (! isset($cabins[$cabin])) {
                continue;
            }

            $base = max(
                0,
                (int) ($cabins[$cabin]['base_fare_minor']
                    ?? $cabins[$cabin]['fare_minor']
                    ?? $routeFares[$cabin.'_minor']
                    ?? 0)
            );

            $cabins[$cabin]['base_fare_minor'] = $base;
            $cabins[$cabin]['fare_minor'] = max(0, (int) ($cabins[$cabin]['fare_minor'] ?? $base));
            $cabins[$cabin]['revenue_minor'] = max(0, (int) ($cabins[$cabin]['revenue_minor'] ?? 0));
            $cabins[$cabin]['fare_bucket'] = $cabins[$cabin]['fare_bucket'] ?? 'initial';
        }

        $commercial['cabins'] = $cabins;
        $commercial['pricing'] = array_merge(
            (array) ($commercial['pricing'] ?? []),
            [
                'mode' => $policy['mode'],
                'strategy' => $policy['strategy'],
                'floor_percent' => $policy['floor_percent'],
                'ceiling_percent' => $policy['ceiling_percent'],
                'initialized_at' => data_get($commercial, 'pricing.initialized_at', now()->toIso8601String()),
            ]
        );
        $data['commercial'] = $commercial;

        $flight->forceFill(['operational_data' => $data])->save();
    }

    public function repriceFlight(
        Flight $flight,
        Carbon $at,
        array $competitionSnapshot = [],
        bool $force = false,
    ): array {
        $flight->loadMissing(['route', 'airline']);

        if (! $flight->route || ! $flight->airline || ! in_array($flight->status, ['scheduled', 'boarding'], true)) {
            return ['changed' => false, 'cabins' => []];
        }

        $this->initializeFlightPricing($flight);
        $flight->refresh();

        $policy = $this->routePricingPolicy($flight->route);
        if ($policy['mode'] !== 'dynamic') {
            return [
                'changed' => false,
                'cabins' => (array) data_get($flight->operational_data, 'commercial.cabins', []),
                'policy' => $policy,
            ];
        }

        $strategy = (array) config('revenue_management.strategies.'.$policy['strategy'], []);
        $occupancyWeight = max(0.0, (float) ($strategy['occupancy_weight'] ?? 1.0));
        $timeWeight = max(0.0, (float) ($strategy['time_weight'] ?? 1.0));
        $marketWeight = max(0.0, (float) ($strategy['market_weight'] ?? 0.55));

        $data = $flight->operational_data ?? [];
        $commercial = $data['commercial'] ?? [];
        $cabins = $commercial['cabins'] ?? [];
        $progress = $this->bookingProgress($flight, $at);
        $hoursToDeparture = max(0.0, $at->diffInMinutes($flight->scheduled_departure_at, false) / 60);
        $departureBucket = $this->departureBucket($hoursToDeparture);
        $marketAverageEconomy = max(0, (int) ($competitionSnapshot['market_average_economy_fare_minor'] ?? 0));
        $competitionMultiplier = max(0.0, (float) ($competitionSnapshot['competition_multiplier'] ?? 1.0));
        $changed = false;
        $decisions = [];

        foreach (['economy', 'business', 'first'] as $cabin) {
            if (! isset($cabins[$cabin])) {
                continue;
            }

            $capacity = max(0, (int) ($cabins[$cabin]['capacity'] ?? 0));
            $booked = max(0, (int) ($cabins[$cabin]['booked'] ?? 0));
            $baseFare = max(0, (int) ($cabins[$cabin]['base_fare_minor'] ?? $cabins[$cabin]['fare_minor'] ?? 0));
            $previousFare = max(0, (int) ($cabins[$cabin]['fare_minor'] ?? $baseFare));

            if ($capacity === 0 || $baseFare <= 0) {
                continue;
            }

            $loadFactor = min(1.0, $booked / max(1, $capacity));
            $loadBucket = $this->loadBucket($loadFactor);

            $occupancyMultiplier = 1 + (((float) $loadBucket['multiplier'] - 1) * $occupancyWeight);
            $timeMultiplier = 1 + (((float) $departureBucket['multiplier'] - 1) * $timeWeight);

            $marketMultiplier = 1.0;
            if ($marketAverageEconomy > 0) {
                $cabinScale = match ($cabin) {
                    'business' => 2.15,
                    'first' => 3.65,
                    default => 1.0,
                };
                $marketReference = $marketAverageEconomy * $cabinScale;
                $marketRatio = min(1.25, max(0.75, $marketReference / max(1, $baseFare)));
                $marketMultiplier *= 1 + (($marketRatio - 1) * $marketWeight);
            }

            if (($competitionSnapshot['competitor_count'] ?? 0) > 0) {
                $pressure = min(1.0, max(0.35, $competitionMultiplier));
                $marketMultiplier *= 0.90 + (0.10 * $pressure);
            }

            $rawFare = (int) round(
                $baseFare
                * $occupancyMultiplier
                * $timeMultiplier
                * $marketMultiplier
            );

            $floorFare = (int) round($baseFare * ($policy['floor_percent'] / 100));
            $ceilingFare = (int) round($baseFare * ($policy['ceiling_percent'] / 100));
            $newFare = max($floorFare, min($ceilingFare, $rawFare));
            $bucketCode = $loadBucket['code'].'+'.$departureBucket['code'];
            $previousBucket = (string) ($cabins[$cabin]['fare_bucket'] ?? 'initial');
            $deltaPercent = $previousFare > 0
                ? abs(($newFare - $previousFare) / $previousFare) * 100
                : 100.0;
            $minimumDelta = max(0.0, (float) config('revenue_management.minimum_reprice_delta_percent', 1.0));

            $apply = $force || $previousBucket !== $bucketCode || $deltaPercent >= $minimumDelta;

            if ($apply && $newFare !== $previousFare) {
                FlightFareEvent::create([
                    'world_id' => $flight->world_id,
                    'airline_id' => $flight->airline_id,
                    'flight_id' => $flight->id,
                    'cabin' => $cabin,
                    'bucket_code' => $bucketCode,
                    'previous_fare_minor' => $previousFare,
                    'new_fare_minor' => $newFare,
                    'base_fare_minor' => $baseFare,
                    'load_factor' => round($loadFactor, 4),
                    'booking_progress' => round($progress, 4),
                    'competition_multiplier' => round($competitionMultiplier, 4),
                    'calculated_at' => $at,
                    'reason' => $this->pricingReason($loadBucket, $departureBucket, $competitionSnapshot),
                    'metadata' => [
                        'strategy' => $policy['strategy'],
                        'floor_percent' => $policy['floor_percent'],
                        'ceiling_percent' => $policy['ceiling_percent'],
                        'occupancy_multiplier' => round($occupancyMultiplier, 4),
                        'time_multiplier' => round($timeMultiplier, 4),
                        'market_multiplier' => round($marketMultiplier, 4),
                        'market_average_economy_fare_minor' => $marketAverageEconomy,
                    ],
                ]);

                $cabins[$cabin]['fare_minor'] = $newFare;
                $changed = true;
            }

            if ($apply) {
                $cabins[$cabin]['fare_bucket'] = $bucketCode;
            }

            $cabins[$cabin]['base_fare_minor'] = $baseFare;
            $cabins[$cabin]['pricing'] = [
                'floor_fare_minor' => $floorFare,
                'ceiling_fare_minor' => $ceilingFare,
                'load_factor' => round($loadFactor, 4),
                'load_bucket' => $loadBucket['code'],
                'departure_bucket' => $departureBucket['code'],
                'hours_to_departure' => round($hoursToDeparture, 2),
                'last_calculated_at' => $at->toIso8601String(),
            ];

            $decisions[$cabin] = [
                'previous_fare_minor' => $previousFare,
                'fare_minor' => (int) $cabins[$cabin]['fare_minor'],
                'base_fare_minor' => $baseFare,
                'bucket_code' => $bucketCode,
                'load_factor' => round($loadFactor, 4),
                'changed' => $apply && $newFare !== $previousFare,
            ];
        }

        $commercial['cabins'] = $cabins;
        $commercial['pricing'] = array_merge(
            (array) ($commercial['pricing'] ?? []),
            [
                'mode' => $policy['mode'],
                'strategy' => $policy['strategy'],
                'floor_percent' => $policy['floor_percent'],
                'ceiling_percent' => $policy['ceiling_percent'],
                'booking_progress' => round($progress, 4),
                'last_repriced_at' => $at->toIso8601String(),
            ]
        );
        $data['commercial'] = $commercial;

        $flight->forceFill(['operational_data' => $data])->save();

        return [
            'changed' => $changed,
            'cabins' => $decisions,
            'policy' => $policy,
        ];
    }

    public function bookingProgress(Flight $flight, Carbon $at): float
    {
        $windowMinutes = max(1440, ((int) config('simulation.booking_window_days', 14)) * 1440);
        $minutesToDeparture = $at->diffInMinutes($flight->scheduled_departure_at, false);

        if ($minutesToDeparture <= 0) {
            return 1.0;
        }

        if ($minutesToDeparture >= $windowMinutes) {
            return 0.08;
        }

        $elapsed = 1 - ($minutesToDeparture / $windowMinutes);

        return min(1.0, max(0.08, 0.08 + (0.92 * pow($elapsed, 1.25))));
    }

    private function loadBucket(float $loadFactor): array
    {
        foreach ((array) config('revenue_management.load_buckets', []) as $bucket) {
            if ($loadFactor <= (float) ($bucket['max_load'] ?? 1.0)) {
                return $bucket;
            }
        }

        return [
            'code' => 'high',
            'label' => 'High Demand',
            'max_load' => 1.0,
            'multiplier' => 1.55,
        ];
    }

    private function departureBucket(float $hoursToDeparture): array
    {
        foreach ((array) config('revenue_management.departure_buckets', []) as $bucket) {
            if ($hoursToDeparture >= (float) ($bucket['min_hours'] ?? 0)) {
                return $bucket;
            }
        }

        return [
            'code' => 'very_late',
            'label' => 'Very Last Minute',
            'min_hours' => 0,
            'multiplier' => 1.42,
        ];
    }

    private function pricingReason(array $loadBucket, array $departureBucket, array $competitionSnapshot): string
    {
        $reasons = [
            (string) ($loadBucket['label'] ?? $loadBucket['code'] ?? 'Auslastung'),
            (string) ($departureBucket['label'] ?? $departureBucket['code'] ?? 'Abflugnähe'),
        ];

        if (($competitionSnapshot['competitor_count'] ?? 0) > 0) {
            $reasons[] = 'Wettbewerb';
        }

        return implode(' · ', $reasons);
    }
}
