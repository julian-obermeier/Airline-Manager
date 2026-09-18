<?php

namespace Tests\Feature;

use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\CrewMember;
use App\Models\CrewQualification;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use App\Services\Simulation\FlightSimulationService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_flight_progresses_books_cabins_and_is_settled_exactly_once(): void
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

        $this->createQualifiedCrew($airline, $type, $frankfurt);

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ASIM',
        ]);

        $procurement = AircraftProcurement::query()->where('registration', 'D-ASIM')->firstOrFail();
        app(ProcurementService::class)->processWorld($world, $procurement->delivery_due_at->copy()->addMinute());

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

        $earlySummary = $simulation->tick(Carbon::parse($departure)->subHours(12));
        $earlyFlight = $flight->fresh();
        $this->assertGreaterThan(0, $earlyFlight->passengers_booked);
        $this->assertGreaterThan(0, (int) data_get($earlyFlight->operational_data, 'commercial.cabins.economy.booked'));
        $this->assertGreaterThan(0, $earlySummary['bookings_updated']);
        $earlyPassengers = $earlyFlight->passengers_booked;

        $simulation->tick(Carbon::parse($departure)->subMinutes(20));
        $boardingFlight = $flight->fresh();
        $this->assertSame('boarding', $boardingFlight->status);
        $this->assertGreaterThanOrEqual($earlyPassengers, $boardingFlight->passengers_booked);
        $this->assertTrue((bool) data_get($boardingFlight->operational_data, 'operations.delay_evaluated'));

        $effectiveDeparture = $boardingFlight->scheduled_departure_at
            ->copy()
            ->addMinutes((int) $boardingFlight->delay_minutes);
        $effectiveArrival = $boardingFlight->scheduled_arrival_at
            ->copy()
            ->addMinutes((int) $boardingFlight->delay_minutes);

        $simulation->tick($effectiveDeparture->copy()->addMinutes(5));
        $this->assertSame('departed', $flight->fresh()->status);
        $this->assertSame('in_flight', $aircraft->fresh()->status);
        $this->assertNull($aircraft->fresh()->current_airport_id);

        $simulation->tick($effectiveArrival->copy()->addMinute());

        $completedFlight = $flight->fresh();
        $completedAircraft = $aircraft->fresh();

        $this->assertSame('completed', $completedFlight->status);
        $this->assertNotNull($completedFlight->actual_departure_at);
        $this->assertNotNull($completedFlight->actual_arrival_at);
        $this->assertGreaterThan(0, (int) data_get($completedFlight->operational_data, 'economics.revenue_minor'));
        $this->assertGreaterThan(0, (int) data_get($completedFlight->operational_data, 'economics.average_fare_minor'));
        $this->assertGreaterThan(0, (int) data_get($completedFlight->operational_data, 'economics.cabin_revenue_minor.economy'));
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

        $simulation->tick($effectiveArrival->copy()->addMinutes(10));

        $this->assertSame($cyclesBeforeSecondTick, (int) $aircraft->fresh()->flight_cycles);
        $this->assertSame($flightTransactionsBeforeSecondTick, LedgerTransaction::query()
            ->where('idempotency_key', 'flight-completion:'.$flight->id)
            ->count());
    }

    private function createQualifiedCrew(Airline $airline, AircraftType $type, Airport $base): void
    {
        $roles = ['captain', 'first_officer', 'cabin_crew', 'cabin_crew', 'cabin_crew'];

        foreach ($roles as $index => $role) {
            $member = CrewMember::create([
                'world_id' => $airline->world_id,
                'airline_id' => $airline->id,
                'home_airport_id' => $base->id,
                'current_airport_id' => $base->id,
                'employee_number' => 'TST'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'first_name' => 'Crew',
                'last_name' => (string) ($index + 1),
                'role' => $role,
                'status' => 'active',
                'monthly_salary_minor' => 500000,
                'currency' => $airline->base_currency,
                'hired_at' => now()->subDay(),
                'max_duty_minutes_day' => 780,
                'min_rest_minutes' => 660,
            ]);

            if (in_array($role, ['captain', 'first_officer'], true)) {
                CrewQualification::create([
                    'crew_member_id' => $member->id,
                    'aircraft_type_id' => $type->id,
                    'qualification_type' => 'type_rating',
                    'valid_from' => now()->subDay()->toDateString(),
                    'status' => 'active',
                ]);
            }
        }
    }
}
