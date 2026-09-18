<?php

namespace Tests\Feature;

use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\CrewMember;
use App\Models\Flight;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Operations\CrewService;
use App\Services\Operations\ProcurementService;
use App\Services\Simulation\FlightSimulationService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_crew_is_required_qualified_assigned_moved_and_paid(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Crew Manager',
            'username' => 'crewmanager',
            'email' => 'crew@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Crew Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'CA',
            'icao_code' => 'CRW',
            'callsign' => 'CREW AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Crew Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('fleet-market.new'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ACRW',
        ]);

        $procurement = AircraftProcurement::query()->where('registration', 'D-ACRW')->firstOrFail();
        app(ProcurementService::class)->processWorld($world, $procurement->delivery_due_at->copy()->addMinute());

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ]);

        $route = $airline->routes()->firstOrFail();
        $aircraft = $airline->aircraft()->firstOrFail();

        $uncrewedDeparture = now()->addHours(2)->setSecond(0);
        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'CA100',
            'scheduled_departure_at' => $uncrewedDeparture->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $uncrewed = Flight::query()->where('flight_number', 'CA100')->firstOrFail();
        $this->assertSame(0, $uncrewed->crewAssignments()->where('status', 'assigned')->count());

        app(FlightSimulationService::class)->tick(
            $uncrewed->scheduled_arrival_at->copy()->addHours(5)
        );

        $uncrewed->refresh();
        $this->assertSame('cancelled', $uncrewed->status);
        $this->assertSame('crew_shortage', data_get($uncrewed->operational_data, 'operations.cancellation_code'));
        $this->assertSame($frankfurt->id, $aircraft->fresh()->current_airport_id);

        $this->post(route('crew.store'), [
            'role' => 'captain',
            'base_airport_id' => $frankfurt->id,
            'monthly_salary' => 9500,
        ])->assertSessionHasErrors('aircraft_type_id');

        $people = [
            ['Anna', 'Captain', 'captain', 9500, $type->id],
            ['Ben', 'First', 'first_officer', 6500, $type->id],
            ['Cara', 'Cabin', 'cabin_crew', 3400, null],
            ['Dina', 'Cabin', 'cabin_crew', 3400, null],
            ['Emil', 'Cabin', 'cabin_crew', 3400, null],
        ];

        foreach ($people as [$first, $last, $role, $salary, $rating]) {
            $payload = [
                'role' => $role,
                'base_airport_id' => $frankfurt->id,
                'monthly_salary' => $salary,
            ];

            if ($rating) {
                $payload['aircraft_type_id'] = $rating;
            }

            $this->post(route('crew.store'), $payload)->assertRedirect(route('crew.index'));
        }

        $this->assertSame(5, CrewMember::query()->where('airline_id', $airline->id)->where('status', 'active')->count());

        $staffedDeparture = now()->addDays(2)->setTime(10, 0);
        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'CA101',
            'scheduled_departure_at' => $staffedDeparture->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $staffed = Flight::query()->where('flight_number', 'CA101')->firstOrFail();
        $snapshot = app(CrewService::class)->staffingSnapshot($staffed);

        $this->assertTrue($snapshot['complete']);
        $this->assertSame(5, $snapshot['assigned']['total']);
        $this->assertSame(3, $snapshot['assigned']['cabin_crew']);

        app(FlightSimulationService::class)->tick(
            $staffed->scheduled_arrival_at->copy()->addHours(5)
        );

        $staffed->refresh();
        $this->assertSame('completed', $staffed->status);

        $this->assertSame(
            5,
            CrewMember::query()
                ->where('airline_id', $airline->id)
                ->where('current_airport_id', $munich->id)
                ->count()
        );

        $payrollAt = ($world->fresh()->simulated_at ?? now())->copy()->addMonth()->startOfMonth()->addDay();
        $payroll = app(CrewService::class)->processPayroll($world->fresh(), $payrollAt);

        $this->assertGreaterThanOrEqual(1, $payroll['payroll_runs']);
        $this->assertGreaterThan(0, $payroll['salary_minor']);
        $this->assertGreaterThanOrEqual(
            1,
            LedgerTransaction::query()
                ->where('airline_id', $airline->id)
                ->where('reference_type', 'crew_payroll')
                ->count()
        );

        $this->get(route('crew.index'))
            ->assertOk()
            ->assertSee('Personal & Crew')
            ->assertSee('EMP0001')
            ->assertSee('E195-E2');

        $this->assertTrue(
            (bool) data_get(
                CrewMember::query()->where('airline_id', $airline->id)->firstOrFail()->metadata,
                'name_generated'
            )
        );
    }
}
