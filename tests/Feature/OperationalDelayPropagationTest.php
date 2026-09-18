<?php

namespace Tests\Feature;

use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\CrewMember;
use App\Models\CrewQualification;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use App\Services\Simulation\FlightSimulationService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDelayPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_inbound_propagates_to_return_leg_when_turnaround_is_no_longer_possible(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Delay Manager',
            'username' => 'delaymanager',
            'email' => 'delay@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $london = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Delay Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'DA',
            'icao_code' => 'DLY',
            'callsign' => 'DELAY',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Delay Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->createQualifiedCrew($airline, $type, $frankfurt);

        $this->post(route('fleet-market.new'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ADLY',
        ])->assertRedirect('/fleet-market');

        $procurement = AircraftProcurement::query()->where('registration', 'D-ADLY')->firstOrFail();
        app(ProcurementService::class)->processWorld($world, $procurement->delivery_due_at->copy()->addMinute());

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $london->id,
        ]);
        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $london->id,
            'destination_airport_id' => $frankfurt->id,
        ]);

        $outbound = $airline->routes()->where('origin_airport_id', $frankfurt->id)->firstOrFail();
        $return = $airline->routes()->where('origin_airport_id', $london->id)->firstOrFail();
        $aircraft = $airline->aircraft()->firstOrFail();
        $start = now()->addDay()->startOfDay();

        $this->post(route('schedules.store'), [
            'aircraft_id' => $aircraft->id,
            'outbound_route_id' => $outbound->id,
            'return_route_id' => $return->id,
            'outbound_flight_number' => 'DA301',
            'return_flight_number' => 'DA302',
            'days_of_week' => [$start->dayOfWeekIso],
            'starts_on' => $start->format('Y-m-d'),
            'departure_time' => '08:00',
            'turnaround_minutes' => 50,
        ])->assertRedirect(route('schedules.index'));

        $schedule = FlightSchedule::query()->firstOrFail();
        $outboundFlight = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->where('flight_number', 'DA301')
            ->orderBy('scheduled_departure_at')
            ->firstOrFail();
        $returnFlight = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->where('flight_number', 'DA302')
            ->orderBy('scheduled_departure_at')
            ->firstOrFail();

        $outboundData = $outboundFlight->operational_data ?? [];
        $outboundData['operations'] = [
            'delay_evaluated' => true,
            'final_delay_minutes' => 240,
            'test_fixture' => true,
        ];
        $outboundFlight->forceFill([
            'delay_minutes' => 240,
            'operational_data' => $outboundData,
        ])->save();

        app(FlightSimulationService::class)->tick(
            $returnFlight->scheduled_departure_at->copy()->subMinutes(120)
        );

        $returnFlight->refresh();

        $this->assertTrue((bool) data_get($returnFlight->operational_data, 'operations.delay_evaluated'));
        $this->assertGreaterThanOrEqual(230, (int) $returnFlight->delay_minutes);
        $this->assertGreaterThan(0, (int) data_get($returnFlight->operational_data, 'operations.rotation_delay_minutes'));
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
