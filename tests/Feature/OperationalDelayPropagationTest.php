<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\World;
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
        $type = AircraftType::query()->where('model', 'A320neo')->firstOrFail();

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ADLY',
        ]);
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
        ]);

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
            'final_delay_minutes' => 60,
            'test_fixture' => true,
        ];
        $outboundFlight->forceFill([
            'delay_minutes' => 60,
            'operational_data' => $outboundData,
        ])->save();

        app(FlightSimulationService::class)->tick(
            $returnFlight->scheduled_departure_at->copy()->subMinutes(120)
        );

        $returnFlight->refresh();

        $this->assertTrue((bool) data_get($returnFlight->operational_data, 'operations.delay_evaluated'));
        $this->assertGreaterThanOrEqual(55, (int) $returnFlight->delay_minutes);
        $this->assertGreaterThan(0, (int) data_get($returnFlight->operational_data, 'operations.rotation_delay_minutes'));
    }
}
