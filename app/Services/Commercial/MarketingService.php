<?php

namespace App\Services\Commercial;

use App\Models\Airline;
use App\Models\AirlineReputationProfile;
use App\Models\AirlineRoute;
use App\Models\Flight;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\MarketingCampaign;
use App\Models\RouteCommercialMetric;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingService
{
    public function ensureProfile(Airline $airline): AirlineReputationProfile
    {
        $serviceQuality = match ($airline->service_concept) {
            'premium' => 68.0,
            'economy' => 48.0,
            default => (float) config('marketing.initial_scores.service_quality', 55),
        };

        return AirlineReputationProfile::query()->firstOrCreate(
            ['airline_id' => $airline->id],
            [
                'world_id' => $airline->world_id,
                'awareness_score' => (float) config('marketing.initial_scores.awareness', 12),
                'reputation_score' => (float) config('marketing.initial_scores.reputation', 50),
                'satisfaction_score' => (float) config('marketing.initial_scores.satisfaction', 55),
                'service_quality_score' => $serviceQuality,
                'brand_value_minor' => 0,
                'completed_flights' => 0,
                'cancelled_flights' => 0,
                'last_evaluated_at' => $airline->world?->simulated_at ?? now(),
                'metadata' => ['source' => 'commercial_profile_v1'],
            ]
        );
    }

    public function ensureRouteMetric(AirlineRoute $route): RouteCommercialMetric
    {
        return RouteCommercialMetric::query()->firstOrCreate(
            ['route_id' => $route->id],
            [
                'world_id' => $route->world_id,
                'airline_id' => $route->airline_id,
                'awareness_score' => (float) config('marketing.initial_scores.route_awareness', 10),
                'satisfaction_score' => (float) config('marketing.initial_scores.satisfaction', 55),
                'historical_load_factor' => 0,
                'completed_flights' => 0,
                'cancelled_flights' => 0,
                'metadata' => ['source' => 'commercial_route_v1'],
            ]
        );
    }

    public function launchCampaign(
        Airline $airline,
        string $name,
        string $scope,
        string $channel,
        int $budgetMinor,
        int $durationDays,
        ?AirlineRoute $route = null,
    ): MarketingCampaign {
        $airline->loadMissing('world');

        if ($scope === 'route' && (! $route || $route->airline_id !== $airline->id)) {
            throw ValidationException::withMessages([
                'route_id' => 'Für eine Streckenkampagne muss eine eigene Route ausgewählt werden.',
            ]);
        }

        if (! array_key_exists($channel, (array) config('marketing.channels', []))) {
            throw ValidationException::withMessages([
                'channel' => 'Der gewählte Marketingkanal ist ungültig.',
            ]);
        }

        $minBudget = (int) config('marketing.campaign_min_budget_minor', 1000000);
        $maxBudget = (int) config('marketing.campaign_max_budget_minor', 500000000);

        if ($budgetMinor < $minBudget || $budgetMinor > $maxBudget) {
            throw ValidationException::withMessages([
                'budget' => 'Das Kampagnenbudget liegt außerhalb des erlaubten Bereichs.',
            ]);
        }

        if ($this->cashBalanceMinor($airline) < $budgetMinor) {
            throw ValidationException::withMessages([
                'budget' => 'Für diese Kampagne ist nicht genügend Liquidität vorhanden.',
            ]);
        }

        $durationDays = max(1, min(90, $durationDays));
        $simulationNow = $airline->world?->simulated_at ?? now();
        $channelConfig = (array) config('marketing.channels.'.$channel, []);
        $reach = max(0.5, (float) ($channelConfig['reach'] ?? 1.0));
        $routeFocus = max(0.5, (float) ($channelConfig['route_focus'] ?? 1.0));
        $budgetEuro = max(1, $budgetMinor / 100);
        $budgetScale = log10(1 + ($budgetEuro / 10000));
        $awarenessGain = min(20.0, 2.0 + ($budgetScale * 5.0 * $reach));
        $boostFactor = $scope === 'route' ? $routeFocus : 1.0;
        $demandBoost = min(
            0.32,
            0.035 + ($budgetScale * 0.055 * $reach * $boostFactor)
        );

        return DB::transaction(function () use (
            $airline,
            $route,
            $name,
            $scope,
            $channel,
            $budgetMinor,
            $durationDays,
            $simulationNow,
            $awarenessGain,
            $demandBoost
        ): MarketingCampaign {
            $profile = $this->ensureProfile($airline);
            $routeMetric = $route ? $this->ensureRouteMetric($route) : null;

            $campaign = MarketingCampaign::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'route_id' => $route?->id,
                'name' => trim($name),
                'scope' => $scope,
                'channel' => $channel,
                'budget_minor' => $budgetMinor,
                'currency' => $airline->base_currency,
                'starts_at' => $simulationNow,
                'ends_at' => $simulationNow->copy()->addDays($durationDays),
                'status' => 'active',
                'demand_boost' => round($demandBoost, 4),
                'awareness_gain' => round($awarenessGain, 2),
                'metadata' => [
                    'duration_days' => $durationDays,
                    'simulation_version' => 'marketing_v1',
                ],
            ]);

            $cash = $this->account($airline, 'CASH', 'Bankguthaben', 'asset');
            $expense = $this->account($airline, 'MARKETING_EXPENSE', 'Marketingkosten', 'expense');

            $transaction = LedgerTransaction::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'idempotency_key' => 'marketing-campaign:'.$campaign->id,
                'reference_type' => 'marketing_campaign',
                'reference_id' => $campaign->id,
                'description' => 'Marketingkampagne '.$campaign->name,
                'occurred_at' => $simulationNow,
                'posted_at' => now(),
                'metadata' => [
                    'scope' => $scope,
                    'channel' => $channel,
                    'budget_minor' => $budgetMinor,
                    'route_id' => $route?->id,
                    'demand_boost' => $demandBoost,
                    'awareness_gain' => $awarenessGain,
                ],
            ]);

            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $expense->id,
                'amount_minor' => $budgetMinor,
                'memo' => 'Kampagne '.$campaign->name,
            ]);
            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $cash->id,
                'amount_minor' => -$budgetMinor,
                'memo' => 'Marketingbudget '.$campaign->name,
            ]);

            $airlineGain = $scope === 'brand' ? $awarenessGain : $awarenessGain * 0.25;
            $profile->forceFill([
                'awareness_score' => $this->score((float) $profile->awareness_score + $airlineGain),
                'last_evaluated_at' => $simulationNow,
            ])->save();

            if ($routeMetric) {
                $routeMetric->forceFill([
                    'awareness_score' => $this->score((float) $routeMetric->awareness_score + $awarenessGain),
                ])->save();
            }

            $this->refreshBrandValue($airline, $profile);

            return $campaign;
        });
    }

    public function cancelCampaign(MarketingCampaign $campaign, Carbon $simulationNow): void
    {
        if ($campaign->status !== 'active') {
            return;
        }

        $campaign->forceFill([
            'status' => 'cancelled',
            'ends_at' => $simulationNow->lessThan($campaign->ends_at) ? $simulationNow : $campaign->ends_at,
            'metadata' => array_merge($campaign->metadata ?? [], [
                'cancelled_at' => $simulationNow->toIso8601String(),
                'refund_minor' => 0,
            ]),
        ])->save();
    }

    public function demandSnapshot(Flight $flight, Carbon $at): array
    {
        $flight->loadMissing(['airline.world', 'route']);

        if (! $flight->airline || ! $flight->route) {
            return [
                'multiplier' => 1.0,
                'campaign_boost' => 0.0,
                'active_campaigns' => [],
            ];
        }

        $profile = $this->ensureProfile($flight->airline);
        $routeMetric = $this->ensureRouteMetric($flight->route);

        $awarenessFactor = 0.82 + (((float) $profile->awareness_score / 100) * 0.28);
        $reputationFactor = 0.85 + (((float) $profile->reputation_score / 100) * 0.25);
        $satisfactionFactor = 0.90 + (((float) $profile->satisfaction_score / 100) * 0.20);
        $serviceFactor = 0.92 + (((float) $profile->service_quality_score / 100) * 0.16);
        $routeFactor = 0.82 + (((float) $routeMetric->awareness_score / 100) * 0.32);

        $campaigns = MarketingCampaign::query()
            ->where('airline_id', $flight->airline_id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->where(function ($query) use ($flight): void {
                $query->where('scope', 'brand')
                    ->orWhere(function ($query) use ($flight): void {
                        $query->where('scope', 'route')
                            ->where('route_id', $flight->route_id);
                    });
            })
            ->get();

        $campaignBoost = 0.0;
        $campaignData = [];

        foreach ($campaigns as $campaign) {
            $effectiveBoost = (float) $campaign->demand_boost;
            if ($campaign->scope === 'brand') {
                $effectiveBoost *= 0.70;
            }

            $campaignBoost += $effectiveBoost;
            $campaignData[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'scope' => $campaign->scope,
                'channel' => $campaign->channel,
                'boost' => round($effectiveBoost, 4),
            ];
        }

        $campaignBoost = min(
            (float) config('marketing.max_active_campaign_boost', 0.40),
            $campaignBoost
        );

        $multiplier = $awarenessFactor
            * $reputationFactor
            * $satisfactionFactor
            * $serviceFactor
            * $routeFactor
            * (1 + $campaignBoost);

        $multiplier = min(
            (float) config('marketing.max_demand_multiplier', 1.65),
            max((float) config('marketing.min_demand_multiplier', 0.55), $multiplier)
        );

        return [
            'multiplier' => round($multiplier, 4),
            'campaign_boost' => round($campaignBoost, 4),
            'airline_awareness' => (float) $profile->awareness_score,
            'reputation' => (float) $profile->reputation_score,
            'satisfaction' => (float) $profile->satisfaction_score,
            'service_quality' => (float) $profile->service_quality_score,
            'route_awareness' => (float) $routeMetric->awareness_score,
            'active_campaigns' => $campaignData,
        ];
    }

    public function recordCompletedFlight(Flight $flight): void
    {
        $flight->loadMissing(['airline.world', 'route', 'aircraft.type']);

        if (! $flight->airline || ! $flight->route) {
            return;
        }

        $profile = $this->ensureProfile($flight->airline);
        $routeMetric = $this->ensureRouteMetric($flight->route);
        $delay = max(0, (int) $flight->delay_minutes);
        $loadFactor = min(1.0, max(0.0, (float) data_get($flight->operational_data, 'load_factor', 0)));

        $serviceBase = match ($flight->airline->service_concept) {
            'premium' => 76.0,
            'economy' => 50.0,
            default => 62.0,
        };

        $punctualityImpact = match (true) {
            $delay <= 5 => 8.0,
            $delay <= 15 => 4.0,
            $delay <= 30 => 0.0,
            $delay <= 60 => -8.0,
            default => -16.0,
        };

        $condition = (float) ($flight->aircraft?->condition_percent ?? 100);
        $technicalImpact = $condition >= 95 ? 3.0 : max(-8.0, ($condition - 90) * 0.8);
        $serviceTarget = $this->score($serviceBase + $technicalImpact);
        $satisfactionTarget = $this->score(
            $serviceBase + $punctualityImpact + (($loadFactor >= 0.70 && $loadFactor <= 0.96) ? 2.0 : 0.0)
        );

        $newService = $this->weighted((float) $profile->service_quality_score, $serviceTarget, 0.08);
        $newSatisfaction = $this->weighted((float) $profile->satisfaction_score, $satisfactionTarget, 0.12);
        $reputationTarget = ($newSatisfaction * 0.55) + ($newService * 0.30) + (($delay <= 15 ? 75 : 45) * 0.15);
        $newReputation = $this->weighted((float) $profile->reputation_score, $reputationTarget, 0.08);
        $newAwareness = $this->score((float) $profile->awareness_score + 0.08 + ($loadFactor * 0.12));

        $profile->forceFill([
            'awareness_score' => $newAwareness,
            'reputation_score' => $newReputation,
            'satisfaction_score' => $newSatisfaction,
            'service_quality_score' => $newService,
            'completed_flights' => (int) $profile->completed_flights + 1,
            'last_evaluated_at' => $flight->actual_arrival_at ?? $flight->scheduled_arrival_at,
        ])->save();

        $routeCompleted = (int) $routeMetric->completed_flights + 1;
        $previousLoad = (float) $routeMetric->historical_load_factor;
        $historicalLoad = (($previousLoad * max(0, $routeCompleted - 1)) + $loadFactor) / $routeCompleted;

        $routeMetric->forceFill([
            'awareness_score' => $this->score((float) $routeMetric->awareness_score + 0.20 + ($loadFactor * 0.35)),
            'satisfaction_score' => $this->weighted((float) $routeMetric->satisfaction_score, $satisfactionTarget, 0.15),
            'historical_load_factor' => round($historicalLoad, 4),
            'completed_flights' => $routeCompleted,
        ])->save();

        $this->refreshBrandValue($flight->airline, $profile);
    }

    public function recordCancellation(Flight $flight, string $reason): void
    {
        $flight->loadMissing(['airline.world', 'route']);

        if (! $flight->airline || ! $flight->route) {
            return;
        }

        $profile = $this->ensureProfile($flight->airline);
        $routeMetric = $this->ensureRouteMetric($flight->route);

        $profile->forceFill([
            'reputation_score' => $this->score((float) $profile->reputation_score - 1.2),
            'satisfaction_score' => $this->score((float) $profile->satisfaction_score - 1.8),
            'cancelled_flights' => (int) $profile->cancelled_flights + 1,
            'last_evaluated_at' => $flight->scheduled_departure_at,
            'metadata' => array_merge($profile->metadata ?? [], [
                'last_cancellation_reason' => $reason,
            ]),
        ])->save();

        $routeMetric->forceFill([
            'satisfaction_score' => $this->score((float) $routeMetric->satisfaction_score - 2.2),
            'cancelled_flights' => (int) $routeMetric->cancelled_flights + 1,
        ])->save();

        $this->refreshBrandValue($flight->airline, $profile);
    }

    public function processWorld(World $world, Carbon $simulationNow): array
    {
        $expired = MarketingCampaign::query()
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->where('ends_at', '<', $simulationNow)
            ->update(['status' => 'completed']);

        return ['campaigns_expired' => $expired];
    }

    public function backfillAirline(Airline $airline): array
    {
        $profile = $this->ensureProfile($airline);
        $routes = AirlineRoute::query()->where('airline_id', $airline->id)->get();
        $created = 0;

        foreach ($routes as $route) {
            $metric = $this->ensureRouteMetric($route);
            if ($metric->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->refreshBrandValue($airline, $profile);

        return ['route_metrics_created' => $created];
    }

    public function cashBalanceMinor(Airline $airline): int
    {
        return (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');
    }

    private function refreshBrandValue(Airline $airline, AirlineReputationProfile $profile): void
    {
        $fleetCount = $airline->aircraft()->count();
        $routeCount = $airline->routes()->count();

        $brandValueMinor = (int) round(
            ((float) $profile->awareness_score * (float) $profile->reputation_score * 150000)
            + ((float) $profile->satisfaction_score * 500000)
            + ($fleetCount * 25000000)
            + ($routeCount * 12000000)
            + ((int) $profile->completed_flights * 250000)
        );

        $profile->forceFill(['brand_value_minor' => max(0, $brandValueMinor)])->save();
    }

    private function score(float $value): float
    {
        return round(min(100.0, max(0.0, $value)), 2);
    }

    private function weighted(float $current, float $target, float $weight): float
    {
        return $this->score(($current * (1 - $weight)) + ($target * $weight));
    }

    private function account(Airline $airline, string $code, string $name, string $type): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(
            ['airline_id' => $airline->id, 'code' => $code],
            [
                'world_id' => $airline->world_id,
                'name' => $name,
                'type' => $type,
                'currency' => $airline->base_currency,
                'is_system' => true,
            ]
        );
    }
}
