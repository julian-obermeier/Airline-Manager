<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\RouteMarketPosition;
use App\Models\User;
use App\Models\World;
use App\Services\Commercial\MarketCompetitionService;
use App\Services\Commercial\MarketingService;
use App\Services\Simulation\FlightSimulationService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketCompetitionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_airlines_compete_for_market_share_and_passenger_demand(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Market Owner',
            'username' => 'marketowner',
            'email' => 'owner@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $muc = Airport::query()->where('iata_code', 'MUC')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Own Air',
            'home_airport_id' => $fra->id,
            'iata_code' => 'OA',
            'icao_code' => 'OWN',
            'callsign' => 'OWN AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $ownAirline = Airline::query()->where('name', 'Own Air')->firstOrFail();
        $ownRoute = $this->route($ownAirline, $fra, $muc, 12900);
        $ownAircraft = $this->aircraft($ownAirline, $type, $fra, 'D-OWN1');

        $at = ($world->simulated_at ?? now())->copy();
        $departure = $at->copy()->addDays(2)->setTime(10, 0);

        $ownFlight = $this->flight(
            $ownAirline,
            $ownRoute,
            $ownAircraft,
            'OA101',
            $departure
        );

        $competition = app(MarketCompetitionService::class);
        $solo = $competition->snapshotForFlight($ownFlight, $at);

        $this->assertSame(0, $solo['competitor_count']);
        $this->assertEqualsWithDelta(1.0, (float) $solo['market_share'], 0.000001);
        $this->assertEqualsWithDelta(1.0, (float) $solo['competition_multiplier'], 0.0001);

        $competitorUser = User::create([
            'name' => 'Competitor Owner',
            'username' => 'competitor',
            'email' => 'competitor@example.test',
            'password' => 'Competition2026',
            'country_code' => 'DE',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
        ]);

        $competitor = Airline::create([
            'world_id' => $world->id,
            'owner_user_id' => $competitorUser->id,
            'home_airport_id' => $fra->id,
            'name' => 'Rival Jet',
            'slug' => 'rival-jet',
            'icao_code' => 'RVJ',
            'iata_code' => 'RJ',
            'callsign' => 'RIVAL JET',
            'country_code' => 'DE',
            'base_currency' => 'EUR',
            'business_model' => 'low_cost',
            'service_concept' => 'economy',
            'target_group' => 'leisure',
            'starting_capital_minor' => 5000000000,
            'status' => 'active',
            'branding' => [],
        ]);

        $rivalRoute = $this->route($competitor, $fra, $muc, 7900);
        $rivalAircraft = $this->aircraft($competitor, $type, $fra, 'D-RIV1');

        $marketing = app(MarketingService::class);
        $rivalProfile = $marketing->ensureProfile($competitor);
        $rivalProfile->forceFill([
            'awareness_score' => 72,
            'reputation_score' => 74,
            'satisfaction_score' => 72,
            'service_quality_score' => 68,
        ])->save();

        $rivalMetric = $marketing->ensureRouteMetric($rivalRoute);
        $rivalMetric->forceFill(['awareness_score' => 76])->save();

        foreach ([7, 9, 12, 17] as $index => $hour) {
            $this->flight(
                $competitor,
                $rivalRoute,
                $rivalAircraft,
                'RJ10'.($index + 1),
                $at->copy()->addDays(2)->setTime($hour, 0)
            );
        }

        $competitive = $competition->snapshotForFlight($ownFlight, $at);

        $this->assertSame(1, $competitive['competitor_count']);
        $this->assertCount(2, $competitive['participants']);

        $shareTotal = collect($competitive['participants'])->sum('market_share');
        $this->assertEqualsWithDelta(1.0, (float) $shareTotal, 0.00001);

        $ownParticipant = collect($competitive['participants'])->firstWhere('airline_id', $ownAirline->id);
        $rivalParticipant = collect($competitive['participants'])->firstWhere('airline_id', $competitor->id);

        $this->assertNotNull($ownParticipant);
        $this->assertNotNull($rivalParticipant);
        $this->assertGreaterThan(
            (float) $ownParticipant['market_share'],
            (float) $rivalParticipant['market_share']
        );
        $this->assertLessThan(1.0, (float) $competitive['competition_multiplier']);
        $this->assertSame(4, (int) $rivalParticipant['weekly_frequency']);
        $this->assertSame(1, (int) $ownParticipant['weekly_frequency']);

        $this->assertSame(
            2,
            RouteMarketPosition::query()
                ->where('world_id', $world->id)
                ->where('origin_airport_id', $fra->id)
                ->where('destination_airport_id', $muc->id)
                ->count()
        );

        app(FlightSimulationService::class)->tick($departure->copy()->subHours(12));

        $ownFlight->refresh();
        $this->assertGreaterThan(0, $ownFlight->passengers_booked);
        $this->assertSame(1, (int) data_get($ownFlight->operational_data, 'commercial.competition.competitor_count'));
        $this->assertLessThan(
            1.0,
            (float) data_get($ownFlight->operational_data, 'commercial.competition.competition_multiplier', 1)
        );
        $this->assertCount(
            2,
            (array) data_get($ownFlight->operational_data, 'commercial.competition.participants', [])
        );

        $this->get(route('market.index'))
            ->assertOk()
            ->assertSee('Markt &amp; Konkurrenz', false)
            ->assertSee('Rival Jet')
            ->assertSee('FRA')
            ->assertSee('MUC');
    }

    private function route(Airline $airline, Airport $origin, Airport $destination, int $economyFareMinor): AirlineRoute
    {
        return AirlineRoute::create([
            'world_id' => $airline->world_id,
            'airline_id' => $airline->id,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'distance_km' => 300,
            'planned_block_minutes' => 75,
            'status' => 'active',
            'settings' => [
                'fares' => [
                    'economy_minor' => $economyFareMinor,
                    'business_minor' => $economyFareMinor * 2,
                    'first_minor' => 0,
                ],
                'demand_index' => 1.0,
            ],
        ]);
    }

    private function aircraft(
        Airline $airline,
        AircraftType $type,
        Airport $airport,
        string $registration
    ): Aircraft {
        return Aircraft::create([
            'world_id' => $airline->world_id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $airport->id,
            'registration' => $registration,
            'flight_hours' => 0,
            'flight_cycles' => 0,
            'condition_percent' => 100,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => 1000000000,
            'currency' => 'EUR',
            'configuration' => [
                'seats' => 132,
                'cabins' => ['economy' => 118, 'business' => 14, 'first' => 0],
            ],
            'metadata' => [],
        ]);
    }

    private function flight(
        Airline $airline,
        AirlineRoute $route,
        Aircraft $aircraft,
        string $flightNumber,
        Carbon $departure
    ): Flight {
        return Flight::create([
            'world_id' => $airline->world_id,
            'airline_id' => $airline->id,
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => $flightNumber,
            'scheduled_departure_at' => $departure,
            'scheduled_arrival_at' => $departure->copy()->addMinutes($route->planned_block_minutes),
            'status' => 'scheduled',
            'delay_minutes' => 0,
            'passengers_booked' => 0,
            'cargo_kg_booked' => 0,
            'operational_data' => [
                'commercial' => [
                    'route_demand_index' => 1.0,
                    'booking_window_days' => 14,
                    'cabins' => [
                        'economy' => [
                            'capacity' => 118,
                            'fare_minor' => (int) data_get($route->settings, 'fares.economy_minor'),
                            'booked' => 0,
                        ],
                        'business' => [
                            'capacity' => 14,
                            'fare_minor' => (int) data_get($route->settings, 'fares.business_minor'),
                            'booked' => 0,
                        ],
                        'first' => [
                            'capacity' => 0,
                            'fare_minor' => 0,
                            'booked' => 0,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
