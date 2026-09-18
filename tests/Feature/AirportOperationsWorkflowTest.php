<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\AirlineAirportStation;
use App\Models\Airport;
use App\Models\AirportSlotReservation;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Operations\AirportOperationsService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportOperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_stations_slots_capacity_and_station_fees_are_enforced(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Airport Manager',
            'username' => 'airportmanager',
            'email' => 'airport@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $frankfurtMetadata = $frankfurt->metadata ?? [];
        $frankfurtMetadata['slot_capacity_15min'] = 1;
        $frankfurt->forceFill(['metadata' => $frankfurtMetadata])->save();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Airport Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'AP',
            'icao_code' => 'APT',
            'callsign' => 'AIRPORT',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Airport Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $aircraftOne = $this->aircraft($airline, $type, $frankfurt, 'D-ASL1');
        $aircraftTwo = $this->aircraft($airline, $type, $frankfurt, 'D-ASL2');

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ])->assertRedirect(route('operations.index'));

        $this->assertSame(
            2,
            AirlineAirportStation::query()->where('airline_id', $airline->id)->where('status', 'active')->count()
        );

        $this->assertDatabaseHas('airline_airport_stations', [
            'airline_id' => $airline->id,
            'airport_id' => $frankfurt->id,
            'station_type' => 'base',
        ]);
        $this->assertDatabaseHas('airline_airport_stations', [
            'airline_id' => $airline->id,
            'airport_id' => $munich->id,
            'station_type' => 'outstation',
        ]);

        $route = $airline->routes()->firstOrFail();
        $departure = now()->addDay()->setTime(10, 0, 0);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraftOne->id,
            'flight_number' => 'AP101',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $this->assertSame(
            2,
            AirportSlotReservation::query()
                ->where('airline_id', $airline->id)
                ->whereHas('flight', fn ($query) => $query->where('flight_number', 'AP101'))
                ->count()
        );

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraftTwo->id,
            'flight_number' => 'AP102',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('scheduled_departure_at');

        $this->assertDatabaseMissing('flights', [
            'world_id' => $world->id,
            'flight_number' => 'AP102',
        ]);

        $flight = $airline->flights()->where('flight_number', 'AP101')->firstOrFail();
        $fees = app(AirportOperationsService::class)->airportFeesForFlight($flight);

        $this->assertGreaterThan(0, $fees['slot_fees_minor']);
        $this->assertGreaterThan($fees['slot_fees_minor'], $fees['total_minor']);

        $stationFeeAt = ($world->simulated_at ?? now())->copy()->addMonth()->startOfMonth()->addDay();
        $stationFees = app(AirportOperationsService::class)->processStationFees($world->fresh(), $stationFeeAt);

        $this->assertGreaterThanOrEqual(2, $stationFees['station_fee_runs']);
        $this->assertGreaterThan(0, $stationFees['station_cost_minor']);
        $this->assertGreaterThanOrEqual(
            2,
            LedgerTransaction::query()
                ->where('airline_id', $airline->id)
                ->where('reference_type', 'airport_station_fee')
                ->count()
        );

        $this->get(route('airport-operations.index'))
            ->assertOk()
            ->assertSee('Stationen & Slots')
            ->assertSee('FRA')
            ->assertSee('MUC')
            ->assertSee('AP101');
    }

    private function aircraft(Airline $airline, AircraftType $type, Airport $airport, string $registration): Aircraft
    {
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
            'currency' => $airline->base_currency,
            'configuration' => [
                'seats' => 132,
                'cabins' => ['economy' => 120, 'business' => 12, 'first' => 0],
            ],
            'metadata' => [],
        ]);
    }
}
