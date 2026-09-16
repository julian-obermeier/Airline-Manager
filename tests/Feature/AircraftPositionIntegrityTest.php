<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\AirlineRoute;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\World;
use App\Services\Operations\FlightLocationGuardService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AircraftPositionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_flights_follow_the_real_aircraft_airport_without_teleporting(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Position Manager',
            'username' => 'positionmanager',
            'email' => 'position@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $muc = Airport::query()->where('iata_code', 'MUC')->firstOrFail();
        $lhr = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Position Air',
            'home_airport_id' => $fra->id,
            'iata_code' => 'PA',
            'icao_code' => 'POS',
            'callsign' => 'POSITION AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Position Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();
        $aircraft = Aircraft::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $fra->id,
            'registration' => 'D-APOS',
            'flight_hours' => 0,
            'flight_cycles' => 0,
            'condition_percent' => 100,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => 100000000,
            'currency' => 'EUR',
            'configuration' => ['seats' => 137, 'cabins' => ['economy' => 125, 'business' => 12, 'first' => 0]],
            'metadata' => [],
        ]);

        $fraLhr = $this->route($world, $airline, $fra, $lhr, 660, 105);
        $lhrFra = $this->route($world, $airline, $lhr, $fra, 660, 105);
        $mucLhr = $this->route($world, $airline, $muc, $lhr, 940, 125);

        $firstDeparture = now()->addHours(4)->startOfMinute();

        $this->post(route('operations.flights.store'), [
            'route_id' => $mucLhr->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'PA900',
            'scheduled_departure_at' => $firstDeparture->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('aircraft_id');

        $this->assertDatabaseMissing('flights', ['flight_number' => 'PA900']);

        $this->post(route('operations.flights.store'), [
            'route_id' => $fraLhr->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'PA101',
            'scheduled_departure_at' => $firstDeparture->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $first = Flight::query()->where('flight_number', 'PA101')->firstOrFail();
        $secondDeparture = $first->scheduled_arrival_at->copy()->addMinutes(60);

        $this->post(route('operations.flights.store'), [
            'route_id' => $lhrFra->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'PA102',
            'scheduled_departure_at' => $secondDeparture->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $this->assertDatabaseHas('flights', ['flight_number' => 'PA102', 'route_id' => $lhrFra->id]);

        $guardAircraft = Aircraft::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $fra->id,
            'registration' => 'D-GARD',
            'flight_hours' => 0,
            'flight_cycles' => 0,
            'condition_percent' => 100,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => 100000000,
            'currency' => 'EUR',
            'configuration' => ['seats' => 137],
            'metadata' => [],
        ]);

        $badFlight = Flight::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'route_id' => $mucLhr->id,
            'aircraft_id' => $guardAircraft->id,
            'flight_number' => 'PA999',
            'scheduled_departure_at' => now()->subMinute(),
            'scheduled_arrival_at' => now()->addHours(2),
            'status' => 'scheduled',
            'delay_minutes' => 0,
            'passengers_booked' => 0,
            'cargo_kg_booked' => 0,
            'operational_data' => [],
        ]);

        $summary = app(FlightLocationGuardService::class)->guardBeforeTick(now());
        $badFlight->refresh();

        $this->assertSame(1, $summary['cancelled_location']);
        $this->assertSame('cancelled', $badFlight->status);
        $this->assertSame('aircraft_wrong_airport', data_get($badFlight->operational_data, 'operations.cancellation_code'));
        $this->assertSame($fra->id, $guardAircraft->fresh()->current_airport_id);
    }

    private function route(World $world, Airline $airline, Airport $origin, Airport $destination, int $distanceKm, int $blockMinutes): AirlineRoute
    {
        return AirlineRoute::create([
            'world_id' => $world->id,
            'airline_id' => $airline->id,
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'distance_km' => $distanceKm,
            'planned_block_minutes' => $blockMinutes,
            'status' => 'active',
            'settings' => [
                'demand_index' => 1.0,
                'fares' => [
                    'economy_minor' => 9900,
                    'business_minor' => 22900,
                    'first_minor' => 0,
                ],
            ],
        ]);
    }
}
