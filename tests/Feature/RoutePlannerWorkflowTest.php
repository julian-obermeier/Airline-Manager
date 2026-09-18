<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\World;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutePlannerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_planner_analyses_market_fleet_fit_and_profitability(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Network Planner',
            'username' => 'networkplanner',
            'email' => 'network@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $muc = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Planner Air',
            'home_airport_id' => $fra->id,
            'iata_code' => 'PA',
            'icao_code' => 'PLN',
            'callsign' => 'PLANNER AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Planner Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();

        Aircraft::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $fra->id,
            'registration' => 'D-APLN',
            'serial_number' => 'PLANNER-001',
            'manufactured_on' => now()->subYear()->toDateString(),
            'flight_hours' => 500,
            'flight_cycles' => 350,
            'condition_percent' => 98,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => $type->reference_purchase_price_minor,
            'currency' => 'EUR',
            'configuration' => [
                'seats' => $type->typical_seats,
                'cabins' => [
                    'economy' => max(1, $type->typical_seats - 12),
                    'business' => 12,
                    'first' => 0,
                ],
            ],
            'metadata' => [],
        ]);

        $response = $this->get(route('route-planner.index', [
            'origin' => $fra->id,
            'destination' => $muc->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('Streckenplaner 2.0')
            ->assertSee('FRA')
            ->assertSee('MUC')
            ->assertSee('Passende eigene Flugzeuge')
            ->assertSee('D-APLN')
            ->assertSee('Break-even LF')
            ->assertSee('Route eröffnen');

        $analysis = $response->viewData('analysis');

        $this->assertNotNull($analysis);
        $this->assertGreaterThan(0, $analysis['distance_km']);
        $this->assertGreaterThan(0, $analysis['block_minutes']);
        $this->assertGreaterThan(0, $analysis['default_fares']['economy_minor']);
        $this->assertSame(1, $analysis['reachable_aircraft_count']);
        $this->assertSame(1, $analysis['aircraft_at_origin_count']);
        $this->assertGreaterThanOrEqual(0, $analysis['candidate_aircraft']->first()['revenue_minor']);
    }
}
