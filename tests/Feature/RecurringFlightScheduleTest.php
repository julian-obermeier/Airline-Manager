<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\FlightSchedule;
use App\Models\World;
use App\Services\Operations\FlightScheduleService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringFlightScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_roundtrip_schedule_generates_future_flights_once_with_turnaround(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Schedule Manager',
            'username' => 'schedulemanager',
            'email' => 'schedule@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $london = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Rotation Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'RA',
            'icao_code' => 'ROT',
            'callsign' => 'ROTATION',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Rotation Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A320neo')->firstOrFail();

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AROT',
        ])->assertRedirect(route('operations.index'));

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
        $day = $start->dayOfWeekIso;

        $this->post(route('schedules.store'), [
            'aircraft_id' => $aircraft->id,
            'outbound_route_id' => $outbound->id,
            'return_route_id' => $return->id,
            'outbound_flight_number' => 'RA201',
            'return_flight_number' => 'RA202',
            'days_of_week' => [$day],
            'starts_on' => $start->format('Y-m-d'),
            'departure_time' => '08:00',
            'turnaround_minutes' => 50,
        ])->assertRedirect(route('schedules.index'));

        $schedule = FlightSchedule::query()->firstOrFail();
        $flights = Flight::query()
            ->where('flight_schedule_id', $schedule->id)
            ->orderBy('scheduled_departure_at')
            ->get();

        $this->assertGreaterThanOrEqual(2, $flights->count());
        $firstOutbound = $flights->firstWhere('flight_number', 'RA201');
        $firstReturn = $flights->firstWhere('flight_number', 'RA202');
        $this->assertNotNull($firstOutbound);
        $this->assertNotNull($firstReturn);
        $this->assertSame($outbound->id, $firstOutbound->route_id);
        $this->assertSame($return->id, $firstReturn->route_id);
        $this->assertSame(50, $firstOutbound->scheduled_arrival_at->diffInMinutes($firstReturn->scheduled_departure_at));
        $this->assertSame('outbound', data_get($firstOutbound->operational_data, 'rotation.leg'));
        $this->assertSame('return', data_get($firstReturn->operational_data, 'rotation.leg'));

        $countBefore = Flight::query()->where('flight_schedule_id', $schedule->id)->count();
        app(FlightScheduleService::class)->generate($schedule, Carbon::now());
        $countAfter = Flight::query()->where('flight_schedule_id', $schedule->id)->count();

        $this->assertSame($countBefore, $countAfter);

        $this->get(route('schedules.index'))
            ->assertOk()
            ->assertSee('RA201 / RA202')
            ->assertSee('FRA → LHR → FRA');
    }
}
