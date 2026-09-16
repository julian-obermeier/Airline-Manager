<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\World;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_airline_can_buy_aircraft_create_route_and_schedule_flight(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Operations Manager',
            'username' => 'opsmanager',
            'email' => 'ops@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ])->assertRedirect('/worlds');

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world))->assertRedirect('/airline/create');

        $this->post('/airline', [
            'name' => 'Operations Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'OA',
            'icao_code' => 'OPS',
            'callsign' => 'OPERATIONS',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ])->assertRedirect('/dashboard');

        $airline = Airline::query()->where('name', 'Operations Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AOPS',
        ])->assertRedirect('/operations');

        $this->assertDatabaseHas('aircraft', [
            'airline_id' => $airline->id,
            'registration' => 'D-AOPS',
            'aircraft_type_id' => $type->id,
        ]);
        $this->assertDatabaseHas('ledger_accounts', [
            'airline_id' => $airline->id,
            'code' => 'FLEET',
        ]);
        $this->assertDatabaseCount('ledger_entries', 4);

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ])->assertRedirect('/operations');

        $route = $airline->routes()->firstOrFail();
        $aircraft = $airline->aircraft()->firstOrFail();

        $this->assertGreaterThan(250, (float) $route->distance_km);
        $this->assertLessThan(400, (float) $route->distance_km);
        $this->assertGreaterThan(45, $route->planned_block_minutes);

        $departure = now()->addDay()->setSecond(0);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'OA101',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ])->assertRedirect('/operations');

        $this->assertDatabaseHas('flights', [
            'airline_id' => $airline->id,
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'OA101',
            'status' => 'scheduled',
        ]);

        $this->get('/operations')
            ->assertOk()
            ->assertSee('D-AOPS')
            ->assertSee('FRA → MUC')
            ->assertSee('OA101');
    }
}
