<?php

namespace Tests\Feature;

use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_airline_can_order_aircraft_receive_it_create_route_price_it_and_schedule_flight(): void
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

        $this->post(route('fleet-market.new'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AOPS',
        ])->assertRedirect('/fleet-market');

        $procurement = AircraftProcurement::query()->where('registration', 'D-AOPS')->firstOrFail();
        $this->assertSame('ordered', $procurement->status);
        $this->assertDatabaseMissing('aircraft', ['registration' => 'D-AOPS']);
        $this->assertDatabaseHas('ledger_accounts', ['airline_id' => $airline->id, 'code' => 'AIRCRAFT_PREPAYMENTS']);

        app(ProcurementService::class)->processWorld($world, $procurement->delivery_due_at->copy()->addMinute());

        $this->assertDatabaseHas('aircraft', [
            'airline_id' => $airline->id,
            'registration' => 'D-AOPS',
            'aircraft_type_id' => $type->id,
            'ownership_type' => 'owned',
        ]);
        $this->assertDatabaseHas('ledger_accounts', ['airline_id' => $airline->id, 'code' => 'FLEET']);

        $aircraft = $airline->aircraft()->firstOrFail();
        $this->assertGreaterThan(0, (int) data_get($aircraft->configuration, 'cabins.economy'));
        $this->assertGreaterThan(0, (int) data_get($aircraft->configuration, 'cabins.business'));

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ])->assertRedirect('/revenue-management');

        $route = $airline->routes()->firstOrFail();

        $this->assertGreaterThan(250, (float) $route->distance_km);
        $this->assertLessThan(400, (float) $route->distance_km);
        $this->assertGreaterThan(45, $route->planned_block_minutes);
        $this->assertGreaterThan(0, (int) data_get($route->settings, 'fares.economy_minor'));
        $this->assertGreaterThan(0, (float) data_get($route->settings, 'demand_index'));

        $this->patch(route('revenue-management.fares.update', $route), [
            'economy_fare' => '89.90',
            'business_fare' => '219.00',
            'first_fare' => '399.00',
        ])->assertRedirect('/operations');

        $route->refresh();
        $this->assertSame(8990, (int) data_get($route->settings, 'fares.economy_minor'));
        $this->assertSame(21900, (int) data_get($route->settings, 'fares.business_minor'));
        $this->assertSame(39900, (int) data_get($route->settings, 'fares.first_minor'));

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

        $flight = Flight::query()->where('flight_number', 'OA101')->firstOrFail();
        $this->assertSame(8990, (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor'));
        $this->assertSame(21900, (int) data_get($flight->operational_data, 'commercial.cabins.business.fare_minor'));
        $this->assertGreaterThan(0, (int) data_get($flight->operational_data, 'commercial.cabins.economy.capacity'));

        $this->get('/operations')
            ->assertOk()
            ->assertSee('D-AOPS')
            ->assertSee('FRA → MUC')
            ->assertSee('OA101')
            ->assertSee('Verfügbar')
            ->assertDontSee('@break');
    }
}
