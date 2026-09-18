<?php

namespace App\Services\Planning;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\AirlineAirportStation;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\World;
use App\Services\Commercial\RevenueManagementService;
use App\Services\Operations\AirportOperationsService;
use Illuminate\Support\Collection;

class RoutePlannerService
{
    public function __construct(
        private readonly RevenueManagementService $revenueManagement,
        private readonly AirportOperationsService $airportOperations,
    ) {
    }

    public function analyse(
        Airline $airline,
        World $world,
        Airport $origin,
        Airport $destination
    ): array {
        $distanceKm = $this->distanceKm($origin, $destination);
        $blockMinutes = max(45, (int) ceil(($distanceKm / 780) * 60) + 45);
        $demandIndex = $this->revenueManagement->demandIndex($origin, $destination);
        $defaultFares = $this->revenueManagement->defaultFares($distanceKm, $airline->business_model);

        $marketRoutes = AirlineRoute::query()
            ->with(['airline', 'origin', 'destination'])
            ->where('world_id', $world->id)
            ->where('origin_airport_id', $origin->id)
            ->where('destination_airport_id', $destination->id)
            ->where('status', 'active')
            ->get();

        $ownExistingRoute = $marketRoutes->firstWhere('airline_id', $airline->id);
        $competitorRoutes = $marketRoutes
            ->where('airline_id', '!=', $airline->id)
            ->values();

        $competitors = $competitorRoutes
            ->unique('airline_id')
            ->map(function (AirlineRoute $route): array {
                $fares = $this->revenueManagement->routeFares($route, $route->airline?->business_model ?? 'hybrid');

                return [
                    'airline_id' => $route->airline_id,
                    'airline_name' => $route->airline?->name ?? 'Unbekannte Airline',
                    'airline_iata' => $route->airline?->iata_code,
                    'economy_fare_minor' => (int) ($fares['economy_minor'] ?? 0),
                    'weekly_frequency' => $route->flights()
                        ->where('scheduled_departure_at', '>=', $world->simulated_at ?? now())
                        ->where('scheduled_departure_at', '<=', ($world->simulated_at ?? now())->copy()->addDays(7))
                        ->where('status', '!=', 'cancelled')
                        ->count(),
                ];
            })
            ->values();

        $marketAverageFareMinor = $competitors->isEmpty()
            ? (int) $defaultFares['economy_minor']
            : max(1, (int) round($competitors->avg('economy_fare_minor')));

        $fleet = Aircraft::query()
            ->with(['type', 'currentAirport'])
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', '!=', 'sold')
            ->orderBy('registration')
            ->get();

        $candidateAircraft = $fleet
            ->filter(fn (Aircraft $aircraft): bool =>
                ! $aircraft->type?->range_km || (float) $aircraft->type->range_km >= $distanceKm
            )
            ->map(fn (Aircraft $aircraft): array =>
                $this->aircraftEconomics(
                    $airline,
                    $aircraft,
                    $origin,
                    $destination,
                    $distanceKm,
                    $blockMinutes,
                    $demandIndex,
                    $defaultFares,
                    $competitors->count(),
                    $marketAverageFareMinor
                )
            )
            ->sortByDesc('fully_allocated_profit_minor')
            ->values();

        $newStationCostMinor = 0;
        foreach ([$origin, $destination] as $airport) {
            $exists = AirlineAirportStation::query()
                ->where('airline_id', $airline->id)
                ->where('airport_id', $airport->id)
                ->where('status', 'active')
                ->exists();

            if (! $exists) {
                $type = $airport->id === $airline->home_airport_id ? 'base' : 'outstation';
                $newStationCostMinor += $this->airportOperations->stationMonthlyCostMinor($airport, $type);
            }
        }

        return [
            'origin' => $origin,
            'destination' => $destination,
            'distance_km' => $distanceKm,
            'block_minutes' => $blockMinutes,
            'demand_index' => $demandIndex,
            'demand_label' => $this->demandLabel($demandIndex),
            'default_fares' => $defaultFares,
            'market_average_fare_minor' => $marketAverageFareMinor,
            'competitor_count' => $competitors->count(),
            'competitors' => $competitors,
            'existing_route' => $ownExistingRoute,
            'candidate_aircraft' => $candidateAircraft,
            'reachable_aircraft_count' => $candidateAircraft->count(),
            'aircraft_at_origin_count' => $candidateAircraft->where('at_origin', true)->count(),
            'new_station_cost_minor' => $newStationCostMinor,
            'opportunity_score' => $this->opportunityScore(
                $demandIndex,
                $competitors->count(),
                $candidateAircraft->count(),
                $candidateAircraft->where('at_origin', true)->count(),
                $ownExistingRoute !== null
            ),
        ];
    }

    public function opportunities(
        Airline $airline,
        World $world,
        Airport $origin,
        Collection $airports,
        int $limit = 18
    ): Collection {
        $fleetRanges = Aircraft::query()
            ->with('type')
            ->where('world_id', $world->id)
            ->where('airline_id', $airline->id)
            ->where('status', '!=', 'sold')
            ->get()
            ->map(fn (Aircraft $aircraft): float => (float) ($aircraft->type?->range_km ?? 0))
            ->filter(fn (float $range): bool => $range > 0);

        $maxRange = (float) ($fleetRanges->max() ?? 0);

        return $airports
            ->reject(fn (Airport $airport): bool => $airport->id === $origin->id)
            ->map(function (Airport $destination) use ($airline, $world, $origin, $maxRange): array {
                $distance = $this->distanceKm($origin, $destination);
                $demand = $this->revenueManagement->demandIndex($origin, $destination);
                $competitors = AirlineRoute::query()
                    ->where('world_id', $world->id)
                    ->where('origin_airport_id', $origin->id)
                    ->where('destination_airport_id', $destination->id)
                    ->where('airline_id', '!=', $airline->id)
                    ->where('status', 'active')
                    ->distinct('airline_id')
                    ->count('airline_id');

                $exists = AirlineRoute::query()
                    ->where('airline_id', $airline->id)
                    ->where('origin_airport_id', $origin->id)
                    ->where('destination_airport_id', $destination->id)
                    ->where('status', 'active')
                    ->exists();

                $reachable = $maxRange <= 0 || $distance <= $maxRange;
                $score = $this->opportunityScore($demand, $competitors, $reachable ? 1 : 0, 0, $exists);

                return [
                    'airport' => $destination,
                    'distance_km' => $distance,
                    'demand_index' => $demand,
                    'competitor_count' => $competitors,
                    'reachable' => $reachable,
                    'existing_route' => $exists,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    private function aircraftEconomics(
        Airline $airline,
        Aircraft $aircraft,
        Airport $origin,
        Airport $destination,
        float $distanceKm,
        int $blockMinutes,
        float $demandIndex,
        array $fares,
        int $competitorCount,
        int $marketAverageFareMinor
    ): array {
        $seats = max(
            1,
            (int) data_get($aircraft->configuration, 'seats', $aircraft->type?->typical_seats ?? 1)
        );
        $configuredCabins = data_get($aircraft->configuration, 'cabins');
        $cabins = is_array($configuredCabins) && array_sum(array_map('intval', $configuredCabins)) > 0
            ? [
                'economy' => max(0, (int) ($configuredCabins['economy'] ?? 0)),
                'business' => max(0, (int) ($configuredCabins['business'] ?? 0)),
                'first' => max(0, (int) ($configuredCabins['first'] ?? 0)),
            ]
            : $this->revenueManagement->cabinLayout($seats, $airline->business_model);

        $competitionFactor = max(0.58, 1 - ($competitorCount * 0.08));
        $priceFactor = pow(
            max(1, $marketAverageFareMinor) / max(1, (int) $fares['economy_minor']),
            0.45
        );

        $revenueMinor = 0;
        $passengers = 0;
        $cabinForecast = [];

        foreach (['economy', 'business', 'first'] as $cabin) {
            $capacity = (int) ($cabins[$cabin] ?? 0);
            if ($capacity <= 0) {
                $cabinForecast[$cabin] = [
                    'capacity' => 0,
                    'passengers' => 0,
                    'fare_minor' => (int) ($fares[$cabin.'_minor'] ?? 0),
                    'revenue_minor' => 0,
                ];
                continue;
            }

            $baseLoad = $this->baseCabinLoadFactor($airline->business_model, $cabin);
            $cabinPriceFactor = $cabin === 'economy' ? $priceFactor : sqrt($priceFactor);
            $loadFactor = min(
                0.96,
                max(0.15, $baseLoad * $demandIndex * $competitionFactor * $cabinPriceFactor)
            );
            $booked = min($capacity, max(0, (int) floor($capacity * $loadFactor)));
            $fareMinor = max(0, (int) ($fares[$cabin.'_minor'] ?? 0));
            $cabinRevenue = $booked * $fareMinor;

            $passengers += $booked;
            $revenueMinor += $cabinRevenue;
            $cabinForecast[$cabin] = [
                'capacity' => $capacity,
                'passengers' => $booked,
                'fare_minor' => $fareMinor,
                'revenue_minor' => $cabinRevenue,
                'load_factor' => round($loadFactor, 4),
            ];
        }

        $blockHours = max(0.25, $blockMinutes / 60);
        $fuelBurn = (float) data_get($aircraft->type?->technical_data, 'fuel_burn_l_per_hour', 2400);
        $fuelLiters = (int) round(($blockHours * $fuelBurn) + 250);
        $fuelCostMinor = $fuelLiters * (int) config('simulation.fuel_price_minor_per_liter', 88);
        $operatingCostMinor = (int) round(150000 + ($seats * 350) + ($distanceKm * 60));

        $movementBase = (int) config('airport_operations.base_movement_fee_minor', 50000);
        $seatFee = $seats * (int) config('airport_operations.per_seat_movement_fee_minor', 250);
        $passengerService = $passengers * (int) config('airport_operations.per_passenger_service_fee_minor', 450);
        $slotFees = ((int) config('airport_operations.slot_fee_minor', 20000)) * 2;
        $airportFeesMinor = (($movementBase + $seatFee + $passengerService) * 2) + $slotFees;

        $crewCostMinor = $this->allocatedCrewCostMinor($airline, $seats, $blockHours);
        $stationAllocationMinor = $this->allocatedStationCostMinor($airline, $origin, $destination);

        $flightContributionMinor = $revenueMinor - $fuelCostMinor - $operatingCostMinor - $airportFeesMinor;
        $fullyAllocatedProfitMinor = $flightContributionMinor - $crewCostMinor - $stationAllocationMinor;

        $loadFactor = $seats > 0 ? min(1, $passengers / $seats) : 0;
        $revenuePerOccupiedSeat = $passengers > 0 ? (int) round($revenueMinor / $passengers) : 0;
        $variableCostMinor = $fuelCostMinor + $operatingCostMinor + $airportFeesMinor;
        $breakEvenPassengers = $revenuePerOccupiedSeat > 0
            ? (int) ceil($variableCostMinor / $revenuePerOccupiedSeat)
            : $seats;
        $breakEvenLoadFactor = min(1.5, $breakEvenPassengers / max(1, $seats));

        return [
            'aircraft' => $aircraft,
            'seats' => $seats,
            'at_origin' => $aircraft->current_airport_id === $origin->id,
            'range_margin_km' => (int) round(((float) ($aircraft->type?->range_km ?? $distanceKm)) - $distanceKm),
            'expected_passengers' => $passengers,
            'expected_load_factor' => round($loadFactor, 4),
            'cabin_forecast' => $cabinForecast,
            'revenue_minor' => $revenueMinor,
            'fuel_liters' => $fuelLiters,
            'fuel_cost_minor' => $fuelCostMinor,
            'operating_cost_minor' => $operatingCostMinor,
            'airport_fees_minor' => $airportFeesMinor,
            'allocated_crew_cost_minor' => $crewCostMinor,
            'allocated_station_cost_minor' => $stationAllocationMinor,
            'flight_contribution_minor' => $flightContributionMinor,
            'fully_allocated_profit_minor' => $fullyAllocatedProfitMinor,
            'break_even_load_factor' => round($breakEvenLoadFactor, 4),
            'monthly_profit_7x_minor' => $fullyAllocatedProfitMinor * 30,
        ];
    }

    private function allocatedCrewCostMinor(Airline $airline, int $seats, float $blockHours): int
    {
        $required = [
            'captain' => 1,
            'first_officer' => 1,
            'cabin_crew' => $airline->business_model === 'cargo'
                ? 0
                : max(1, (int) ceil($seats / max(1, (int) config('crew.cabin_seats_per_member', 50)))),
        ];

        $total = 0;

        foreach ($required as $role => $count) {
            if ($count <= 0) continue;

            $averageMonthly = (int) round(
                CrewMember::query()
                    ->where('airline_id', $airline->id)
                    ->where('role', $role)
                    ->where('status', 'active')
                    ->avg('monthly_salary_minor')
                ?: config('crew.default_salary_minor.'.$role, 0)
            );

            $hourly = $averageMonthly / 160;
            $total += (int) round($hourly * $blockHours * $count);
        }

        return max(0, $total);
    }

    private function allocatedStationCostMinor(
        Airline $airline,
        Airport $origin,
        Airport $destination
    ): int {
        $monthly = 0;

        foreach ([$origin, $destination] as $airport) {
            $station = AirlineAirportStation::query()
                ->where('airline_id', $airline->id)
                ->where('airport_id', $airport->id)
                ->where('status', 'active')
                ->first();

            if ($station) {
                $monthly += (int) $station->monthly_cost_minor;
            } else {
                $type = $airport->id === $airline->home_airport_id ? 'base' : 'outstation';
                $monthly += $this->airportOperations->stationMonthlyCostMinor($airport, $type);
            }
        }

        return (int) round($monthly / 30 / 4);
    }

    private function opportunityScore(
        float $demandIndex,
        int $competitorCount,
        int $reachableAircraft,
        int $aircraftAtOrigin,
        bool $alreadyExists
    ): float {
        $score = 50;
        $score += ($demandIndex - 1.0) * 120;
        $score -= min(28, $competitorCount * 6);
        $score += $reachableAircraft > 0 ? 14 : -35;
        $score += $aircraftAtOrigin > 0 ? 6 : 0;
        $score -= $alreadyExists ? 12 : 0;

        return round(min(100, max(0, $score)), 1);
    }

    private function demandLabel(float $index): string
    {
        return match (true) {
            $index >= 1.12 => 'Sehr hoch',
            $index >= 1.04 => 'Hoch',
            $index >= 0.96 => 'Normal',
            $index >= 0.90 => 'Niedrig',
            default => 'Sehr niedrig',
        };
    }

    private function baseCabinLoadFactor(string $businessModel, string $cabin): float
    {
        return match ($cabin) {
            'economy' => match ($businessModel) {
                'low_cost' => 0.90,
                'full_service' => 0.80,
                'regional' => 0.78,
                'cargo' => 0.25,
                default => 0.84,
            },
            'business' => match ($businessModel) {
                'low_cost' => 0.42,
                'full_service' => 0.69,
                'regional' => 0.52,
                'cargo' => 0.10,
                default => 0.60,
            },
            default => match ($businessModel) {
                'full_service' => 0.50,
                'cargo' => 0.05,
                default => 0.38,
            },
        };
    }

    private function distanceKm(Airport $origin, Airport $destination): float
    {
        $earthRadius = 6371;
        $lat1 = deg2rad((float) $origin->latitude);
        $lat2 = deg2rad((float) $destination->latitude);
        $deltaLat = deg2rad((float) $destination->latitude - (float) $origin->latitude);
        $deltaLon = deg2rad((float) $destination->longitude - (float) $origin->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
