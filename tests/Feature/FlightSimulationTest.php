<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Simulation\FlightSimulationService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_flight_progresses_and_is_settled_exactly_once(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Simulation Manager',
            'username' => 'simmanager',
            'email' => 'simulation@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Simulation Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'SA',
            'icao_code' => 'SIM',
            'callsign' => 'SIMULATION',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Simulation Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ASIM',
        ]);
        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ]);

        $route = $airline->routes()->firstOrFail();
        $aircraft = $airline->aircraft()->firstOrFail();
        $departure = now()->addDay()->setSecond(0);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'SA101',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ]);

        $flight = Flight::query()->where('flight_number', 'SA101')->firstOrFail();
        $simulation = app(FlightSimulationService::class);

        $simulation->tick(Carbon::parse($departure)->subMinutes(20));
        $this->assertSame('boarding', $flight->fresh()->status);
        $this->assertGreaterThan(0, $flight->fresh()->passengers_booked);

        $simulation->tick(Carbon::parse($departure)->addMinutes(5));
        $this->assertSame('departed', $flight->fresh()->status);
        $this->assertSame('in_flight', $aircraft->fresh()->status);
        $this->assertNull($aircraft->fresh()->current_airport_id);

        $simulation->tick($flight->scheduled_arrival_at->copy()->addMinute());

        $completedFlight = $flight->fresh();
        $completedAircraft = $aircraft->fresh();

        $this->assertSame('completed', $completedFlight->status);
        $this->assertNotNull($completedFlight->actual_departure_at);
        $this->assertNotNull($completedFlight->actual_arrival_at);
        $this->assertGreaterThan(0, (int) data_get($completedFlight->operational_data, 'economics.revenue_minor'));
        $this->assertNotNull(data_get($completedFlight->operational_data, 'economics.profit_minor'));

        $this->assertSame('available', $completedAircraft->status);
        $this->assertSame($munich->id, $completedAircraft->current_airport_id);
        $this->assertSame(1, (int) $completedAircraft->flight_cycles);
        $this->assertGreaterThan(0, (float) $completedAircraft->flight_hours);
        $this->assertLessThan(100, (float) $completedAircraft->condition_percent);

        $this->assertSame(1, LedgerTransaction::query()
            ->where('idempotency_key', 'flight-completion:'.$flight->id)
            ->count());

        $cyclesBeforeSecondTick = (int) $completedAircraft->flight_cycles;
        $flightTransactionsBeforeSecondTick = LedgerTransaction::query()
            ->where('idempotency_key', 'flight-completion:'.$flight->id)
            ->count();

        $simulation->tick($flight->scheduled_arrival_at->copy()->addMinutes(10));

        $this->assertSame($cyclesBeforeSecondTick, (int) $aircraft->fresh()->flight_cycles);
        $this->assertSame($flightTransactionsBeforeSecondTick, LedgerTransaction::query()
            ->where('idempotency_key', 'flight-completion:'.$flight->id)
            ->count());
    }
}
