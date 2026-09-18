<?php

namespace App\Services\Commercial;

use App\Models\AirlineRoute;
use App\Models\Flight;
use App\Models\MarketingCampaign;
use App\Models\RouteMarketPosition;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MarketCompetitionService
{
    public function __construct(
        private readonly RevenueManagementService $revenueManagement,
        private readonly MarketingService $marketing,
    ) {
    }

    public function snapshotForFlight(Flight $flight, Carbon $at): array
    {
        $flight->loadMissing(['route.origin', 'route.destination', 'route.airline']);

        if (! $flight->route) {
            return $this->emptySnapshot();
        }

        return $this->marketSnapshot($flight->route, $at);
    }

    public function marketSnapshot(AirlineRoute $subjectRoute, Carbon $at): array
    {
        $subjectRoute->loadMissing(['origin', 'destination', 'airline']);

        if (! $subjectRoute->origin || ! $subjectRoute->destination || ! $subjectRoute->airline) {
            return $this->emptySnapshot();
        }

        $windowDays = max(1, (int) config('competition.window_days', 7));
        $windowEnd = $at->copy()->addDays($windowDays);

        $routes = AirlineRoute::query()
            ->with(['airline.world', 'origin', 'destination'])
            ->where('world_id', $subjectRoute->world_id)
            ->where('origin_airport_id', $subjectRoute->origin_airport_id)
            ->where('destination_airport_id', $subjectRoute->destination_airport_id)
            ->where('status', 'active')
            ->whereHas('airline', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($subjectRoute, $at, $windowEnd): void {
                $query->where('id', $subjectRoute->id)
                    ->orWhereHas('flights', function ($query) use ($at, $windowEnd): void {
                        $query->where('scheduled_departure_at', '>=', $at)
                            ->where('scheduled_departure_at', '<=', $windowEnd)
                            ->where('status', '!=', 'cancelled');
                    });
            })
            ->get();

        if (! $routes->contains('id', $subjectRoute->id)) {
            $routes->push($subjectRoute);
        }

        $raw = $routes->map(function (AirlineRoute $route) use ($at, $windowEnd): array {
            $airline = $route->airline;
            $profile = $this->marketing->ensureProfile($airline);
            $metric = $this->marketing->ensureRouteMetric($route);
            $fares = $this->revenueManagement->routeFares($route, $airline->business_model);

            $flights = Flight::query()
                ->with(['aircraft.type'])
                ->where('route_id', $route->id)
                ->where('scheduled_departure_at', '>=', $at)
                ->where('scheduled_departure_at', '<=', $windowEnd)
                ->where('status', '!=', 'cancelled')
                ->orderBy('scheduled_departure_at')
                ->get();

            $weeklySeatCapacity = (int) $flights->sum(function (Flight $flight): int {
                return max(
                    0,
                    (int) data_get(
                        $flight->aircraft?->configuration,
                        'seats',
                        $flight->aircraft?->type?->typical_seats ?? 0
                    )
                );
            });

            $campaignBoost = $this->campaignBoost($route, $at);

            return [
                'route' => $route,
                'airline' => $airline,
                'profile' => $profile,
                'metric' => $metric,
                'economy_fare_minor' => max(1, (int) $fares['economy_minor']),
                'weekly_frequency' => $flights->count(),
                'weekly_seat_capacity' => $weeklySeatCapacity,
                'schedule_factor' => $this->scheduleFactor($flights, $airline->target_group),
                'campaign_boost' => $campaignBoost,
            ];
        });

        $marketAverageFareMinor = (int) round($raw->avg('economy_fare_minor') ?: 0);
        $medianFareMinor = $this->median($raw->pluck('economy_fare_minor'));
        $maxFrequency = max(1, (int) $raw->max('weekly_frequency'));

        $weights = (array) config('competition.weights', []);
        $minimum = (float) config('competition.factor_limits.minimum', 0.40);
        $maximum = (float) config('competition.factor_limits.maximum', 1.60);

        $scored = $raw->map(function (array $participant) use (
            $medianFareMinor,
            $maxFrequency,
            $weights,
            $minimum,
            $maximum
        ): array {
            $profile = $participant['profile'];
            $metric = $participant['metric'];

            $price = $this->clamp(
                pow(max(1, $medianFareMinor) / max(1, $participant['economy_fare_minor']), 0.70),
                $minimum,
                $maximum
            );

            $frequencyRatio = $participant['weekly_frequency'] > 0
                ? sqrt($participant['weekly_frequency'] / $maxFrequency)
                : 0.0;
            $frequency = $this->clamp(0.40 + ($frequencyRatio * 1.00), $minimum, $maximum);

            $reputation = $this->clamp(
                0.60 + (((float) $profile->reputation_score / 100) * 0.80),
                $minimum,
                $maximum
            );

            $service = $this->clamp(
                0.65 + (((float) $profile->service_quality_score / 100) * 0.70),
                $minimum,
                $maximum
            );

            $combinedAwareness = (
                ((float) $profile->awareness_score * 0.45)
                + ((float) $metric->awareness_score * 0.55)
            );
            $awareness = $this->clamp(
                0.55 + (($combinedAwareness / 100) * 0.90),
                $minimum,
                $maximum
            );

            $schedule = $this->clamp(
                (float) $participant['schedule_factor'],
                $minimum,
                $maximum
            );

            $marketing = $this->clamp(
                1.0 + (float) $participant['campaign_boost'],
                $minimum,
                $maximum
            );

            $factors = compact(
                'price',
                'frequency',
                'reputation',
                'service',
                'awareness',
                'schedule',
                'marketing'
            );

            $score = 0.0;
            foreach ($factors as $key => $factor) {
                $score += $factor * (float) ($weights[$key] ?? 0);
            }

            if ($score <= 0) {
                $score = array_sum($factors) / max(1, count($factors));
            }

            $participant['factors'] = array_map(
                fn (float $value): float => round($value, 4),
                $factors
            );
            $participant['competition_score'] = round(max(0.0001, $score), 4);

            return $participant;
        });

        $totalScore = max(0.0001, (float) $scored->sum('competition_score'));
        $participantCount = $scored->count();
        $floor = (float) config('competition.competition_multiplier.floor', 0.35);
        $shareWeight = (float) config('competition.competition_multiplier.share_weight', 0.65);

        $participants = $scored
            ->map(function (array $participant) use (
                $totalScore,
                $participantCount,
                $floor,
                $shareWeight,
                $marketAverageFareMinor,
                $at
            ): array {
                $share = $participant['competition_score'] / $totalScore;
                $competitionMultiplier = $participantCount <= 1
                    ? 1.0
                    : min(1.0, max($floor, $floor + ($shareWeight * $share)));

                $position = RouteMarketPosition::query()->updateOrCreate(
                    ['route_id' => $participant['route']->id],
                    [
                        'world_id' => $participant['route']->world_id,
                        'airline_id' => $participant['route']->airline_id,
                        'origin_airport_id' => $participant['route']->origin_airport_id,
                        'destination_airport_id' => $participant['route']->destination_airport_id,
                        'market_key' => $this->marketKey($participant['route']),
                        'competition_score' => round($participant['competition_score'], 4),
                        'market_share' => round($share, 6),
                        'competition_multiplier' => round($competitionMultiplier, 4),
                        'weekly_frequency' => (int) $participant['weekly_frequency'],
                        'weekly_seat_capacity' => (int) $participant['weekly_seat_capacity'],
                        'average_economy_fare_minor' => (int) $participant['economy_fare_minor'],
                        'competitor_count' => max(0, $participantCount - 1),
                        'calculated_at' => $at,
                        'factors' => $participant['factors'] + [
                            'campaign_boost' => round((float) $participant['campaign_boost'], 4),
                        ],
                        'metadata' => [
                            'market_average_economy_fare_minor' => $marketAverageFareMinor,
                            'simulation_version' => 'competition_v1',
                        ],
                    ]
                );

                return [
                    'route_id' => $participant['route']->id,
                    'airline_id' => $participant['airline']->id,
                    'airline_name' => $participant['airline']->name,
                    'airline_iata' => $participant['airline']->iata_code,
                    'market_share' => round($share, 6),
                    'competition_multiplier' => round($competitionMultiplier, 4),
                    'competition_score' => round($participant['competition_score'], 4),
                    'weekly_frequency' => (int) $participant['weekly_frequency'],
                    'weekly_seat_capacity' => (int) $participant['weekly_seat_capacity'],
                    'economy_fare_minor' => (int) $participant['economy_fare_minor'],
                    'campaign_boost' => round((float) $participant['campaign_boost'], 4),
                    'factors' => $participant['factors'],
                    'position_id' => $position->id,
                ];
            })
            ->sortByDesc('market_share')
            ->values()
            ->map(function (array $participant, int $index): array {
                $participant['rank'] = $index + 1;

                return $participant;
            });

        $subject = $participants->firstWhere('route_id', $subjectRoute->id);

        if (! $subject) {
            return $this->emptySnapshot();
        }

        $strongestCompetitor = $participants
            ->where('route_id', '!=', $subjectRoute->id)
            ->first();

        return [
            'market_key' => $this->marketKey($subjectRoute),
            'origin' => $subjectRoute->origin->iata_code,
            'destination' => $subjectRoute->destination->iata_code,
            'participant_count' => $participantCount,
            'competitor_count' => max(0, $participantCount - 1),
            'market_average_economy_fare_minor' => $marketAverageFareMinor,
            'market_share' => $subject['market_share'],
            'rank' => $subject['rank'],
            'competition_score' => $subject['competition_score'],
            'competition_multiplier' => $subject['competition_multiplier'],
            'weekly_frequency' => $subject['weekly_frequency'],
            'weekly_seat_capacity' => $subject['weekly_seat_capacity'],
            'economy_fare_minor' => $subject['economy_fare_minor'],
            'strongest_competitor' => $strongestCompetitor,
            'participants' => $participants->all(),
            'calculated_at' => $at->toIso8601String(),
        ];
    }

    public function refreshWorld(World $world, Carbon $at): array
    {
        $routes = AirlineRoute::query()
            ->with(['origin', 'destination', 'airline'])
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->whereHas('airline', fn ($query) => $query->where('status', 'active'))
            ->get();

        $seen = [];
        $markets = 0;
        $positions = 0;

        foreach ($routes as $route) {
            $key = $this->marketKey($route);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $snapshot = $this->marketSnapshot($route, $at);
            $markets++;
            $positions += (int) ($snapshot['participant_count'] ?? 0);
        }

        return [
            'markets' => $markets,
            'positions' => $positions,
        ];
    }

    public function backfillAirline(\App\Models\Airline $airline, Carbon $at): array
    {
        $markets = 0;

        AirlineRoute::query()
            ->with(['origin', 'destination', 'airline'])
            ->where('airline_id', $airline->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (AirlineRoute $route) use ($at, &$markets): void {
                $this->marketSnapshot($route, $at);
                $markets++;
            });

        return ['markets' => $markets];
    }

    private function campaignBoost(AirlineRoute $route, Carbon $at): float
    {
        $campaigns = MarketingCampaign::query()
            ->where('airline_id', $route->airline_id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->where(function ($query) use ($route): void {
                $query->where('scope', 'brand')
                    ->orWhere(function ($query) use ($route): void {
                        $query->where('scope', 'route')
                            ->where('route_id', $route->id);
                    });
            })
            ->get();

        $boost = 0.0;

        foreach ($campaigns as $campaign) {
            $value = (float) $campaign->demand_boost;
            $boost += $campaign->scope === 'brand' ? $value * 0.70 : $value;
        }

        return min(
            (float) config('marketing.max_active_campaign_boost', 0.40),
            max(0.0, $boost)
        );
    }

    private function scheduleFactor(Collection $flights, ?string $targetGroup): float
    {
        if ($flights->isEmpty()) {
            return 0.40;
        }

        $scores = $flights->map(function (Flight $flight) use ($targetGroup): float {
            $hour = (int) $flight->scheduled_departure_at->format('G');

            return match ($targetGroup) {
                'business' => match (true) {
                    $hour >= 6 && $hour <= 9 => 1.35,
                    $hour >= 16 && $hour <= 19 => 1.25,
                    $hour >= 10 && $hour <= 15 => 1.00,
                    default => 0.75,
                },
                'leisure' => match (true) {
                    $hour >= 8 && $hour <= 18 => 1.18,
                    $hour >= 6 && $hour <= 7 => 0.95,
                    $hour >= 19 && $hour <= 21 => 0.90,
                    default => 0.70,
                },
                default => match (true) {
                    $hour >= 7 && $hour <= 19 => 1.15,
                    $hour >= 6 && $hour <= 21 => 0.95,
                    default => 0.75,
                },
            };
        });

        $spreadBonus = min(0.15, max(0, $flights->count() - 1) * 0.02);

        return round(min(1.45, ((float) $scores->avg()) + $spreadBonus), 4);
    }

    private function median(Collection $values): int
    {
        $sorted = $values->map(fn ($value): int => (int) $value)->sort()->values();
        $count = $sorted->count();

        if ($count === 0) {
            return 1;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return max(1, (int) $sorted[$middle]);
        }

        return max(1, (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2));
    }

    private function marketKey(AirlineRoute $route): string
    {
        return $route->world_id.':'.$route->origin_airport_id.':'.$route->destination_airport_id;
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return min($maximum, max($minimum, $value));
    }

    private function emptySnapshot(): array
    {
        return [
            'market_key' => null,
            'origin' => null,
            'destination' => null,
            'participant_count' => 0,
            'competitor_count' => 0,
            'market_average_economy_fare_minor' => 0,
            'market_share' => 1.0,
            'rank' => 1,
            'competition_score' => 1.0,
            'competition_multiplier' => 1.0,
            'weekly_frequency' => 0,
            'weekly_seat_capacity' => 0,
            'economy_fare_minor' => 0,
            'strongest_competitor' => null,
            'participants' => [],
            'calculated_at' => null,
        ];
    }
}
