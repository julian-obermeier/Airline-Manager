<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightFareEvent;
use App\Models\World;
use App\Services\Commercial\RevenueManagementService;
use App\Services\Simulation\FlightSimulationService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_fares_reprice_new_inventory_without_repricing_existing_revenue(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Revenue Manager',
            'username' => 'revenuemanager',
            'email' => 'revenue@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $muc = Airport::query()->where('iata_code', 'MUC')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Yield Air',
            'home_airport_id' => $fra->id,
            'iata_code' => 'YA',
            'icao_code' => 'YLD',
            'callsign' => 'YIELD AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Yield Air')->firstOrFail();

        $aircraft = Aircraft::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $fra->id,
            'registration' => 'D-YLD1',
            'flight_hours' => 0,
            'flight_cycles' => 0,
            'condition_percent' => 100,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => 1000000000,
            'currency' => 'EUR',
            'configuration' => [
                'seats' => 132,
                'cabins' => [
                    'economy' => 118,
                    'business' => 14,
                    'first' => 0,
                ],
            ],
            'metadata' => [],
        ]);

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $fra->id,
            'destination_airport_id' => $muc->id,
        ])->assertRedirect(route('operations.index'));

        $route = $airline->routes()->firstOrFail();

        $this->patch(route('revenue-management.fares.update', $route), [
            'economy_fare' => '100.00',
            'business_fare' => '220.00',
            'first_fare' => '0.00',
        ])->assertRedirect(route('revenue-management.index'));

        $this->patch(route('revenue-management.policy.update', $route), [
            'mode' => 'dynamic',
            'strategy' => 'balanced',
            'floor_percent' => 70,
            'ceiling_percent' => 220,
        ])->assertRedirect(route('revenue-management.index'));

        $departure = now()->addDays(5)->setTime(12, 0, 0);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'YA101',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $flight = Flight::query()->where('flight_number', 'YA101')->firstOrFail();

        $this->assertSame(
            10000,
            (int) data_get($flight->operational_data, 'commercial.cabins.economy.base_fare_minor')
        );
        $this->assertSame(
            10000,
            (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor')
        );
        $this->assertSame(
            0,
            (int) data_get($flight->operational_data, 'commercial.cabins.economy.revenue_minor')
        );

        $firstTick = Carbon::parse($departure)->subHours(12);
        app(FlightSimulationService::class)->tick($firstTick);

        $flight->refresh();

        $firstFare = (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor');
        $firstBooked = (int) data_get($flight->operational_data, 'commercial.cabins.economy.booked');
        $firstRevenue = (int) data_get($flight->operational_data, 'commercial.cabins.economy.revenue_minor');
        $firstBucket = (string) data_get($flight->operational_data, 'commercial.cabins.economy.fare_bucket');

        $this->assertNotSame(10000, $firstFare);
        $this->assertGreaterThan(0, $firstBooked);
        $this->assertSame($firstBooked * $firstFare, $firstRevenue);
        $this->assertNotSame('initial', $firstBucket);
        $this->assertGreaterThan(
            0,
            FlightFareEvent::query()
                ->where('flight_id', $flight->id)
                ->where('cabin', 'economy')
                ->count()
        );

        $secondTick = Carbon::parse($departure)->subHour();
        app(FlightSimulationService::class)->tick($secondTick);

        $flight->refresh();

        $secondFare = (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor');
        $secondBooked = (int) data_get($flight->operational_data, 'commercial.cabins.economy.booked');
        $secondRevenue = (int) data_get($flight->operational_data, 'commercial.cabins.economy.revenue_minor');

        $this->assertGreaterThanOrEqual($firstBooked, $secondBooked);
        $this->assertGreaterThanOrEqual($firstRevenue, $secondRevenue);

        $newBookings = $secondBooked - $firstBooked;
        if ($newBookings > 0) {
            $this->assertSame(
                $firstRevenue + ($newBookings * $secondFare),
                $secondRevenue
            );
        } else {
            $this->assertSame($firstRevenue, $secondRevenue);
        }

        $this->patch(route('revenue-management.policy.update', $route), [
            'mode' => 'manual',
            'strategy' => 'balanced',
            'floor_percent' => 70,
            'ceiling_percent' => 220,
        ])->assertRedirect(route('revenue-management.index'));

        $flight->refresh();
        $manualFareBefore = (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor');

        $result = app(RevenueManagementService::class)->repriceFlight(
            $flight,
            Carbon::parse($departure)->subMinutes(30),
            [],
            true
        );

        $flight->refresh();

        $this->assertFalse($result['changed']);
        $this->assertSame(
            $manualFareBefore,
            (int) data_get($flight->operational_data, 'commercial.cabins.economy.fare_minor')
        );
        $this->assertSame(
            'manual',
            (string) data_get($flight->operational_data, 'commercial.pricing.mode')
        );

        $this->get(route('revenue-management.index'))
            ->assertOk()
            ->assertSee('Revenue Management')
            ->assertSee('YA101')
            ->assertSee('FRA')
            ->assertSee('MUC');
    }
}
