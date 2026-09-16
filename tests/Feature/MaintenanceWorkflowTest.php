<?php

namespace Tests\Feature;

use App\Models\AircraftMaintenanceEvent;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Operations\MaintenanceService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_airline_can_plan_and_complete_maintenance_with_real_operational_effects(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Technical Manager',
            'username' => 'technicalmanager',
            'email' => 'technical@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ])->assertRedirect('/worlds');

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world))->assertRedirect('/airline/create');
        $this->post('/airline', [
            'name' => 'Technical Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'TA',
            'icao_code' => 'TCH',
            'callsign' => 'TECHNICAL',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ])->assertRedirect('/dashboard');

        $airline = Airline::query()->where('name', 'Technical Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $this->post(route('operations.fleet.purchase'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-ATCH',
        ])->assertRedirect('/operations');

        $aircraft = $airline->aircraft()->firstOrFail();
        $aircraft->forceFill([
            'condition_percent' => 74,
            'flight_hours' => 610,
            'flight_cycles' => 405,
        ])->save();

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ])->assertRedirect('/operations');

        $route = $airline->routes()->firstOrFail();
        $plannedStart = now()->addDay()->setSecond(0);

        $this->post(route('maintenance.store'), [
            'aircraft_id' => $aircraft->id,
            'check_type' => 'a_check',
            'planned_start_at' => $plannedStart->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('maintenance.index'));

        $event = AircraftMaintenanceEvent::query()->firstOrFail();
        $this->assertSame('planned', $event->status);
        $this->assertSame('a_check', $event->check_type);
        $this->assertGreaterThan(0, $event->cost_minor);
        $this->assertGreaterThan($event->planned_start_at, $event->planned_end_at);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'TA501',
            'scheduled_departure_at' => $plannedStart->copy()->addHour()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('scheduled_departure_at');

        $maintenance = app(MaintenanceService::class);
        $startSummary = $maintenance->processWorld($world, $plannedStart->copy()->addMinute());
        $this->assertSame(1, $startSummary['maintenance_started']);

        $event->refresh();
        $aircraft->refresh();
        $this->assertSame('in_progress', $event->status);
        $this->assertSame('maintenance', $aircraft->status);

        $endSummary = $maintenance->processWorld($world, $event->planned_end_at->copy()->addMinute());
        $this->assertSame(1, $endSummary['maintenance_completed']);

        $event->refresh();
        $aircraft->refresh();
        $this->assertSame('completed', $event->status);
        $this->assertSame('available', $aircraft->status);
        $this->assertGreaterThanOrEqual(97, (float) $aircraft->condition_percent);
        $this->assertSame(610.0, (float) data_get($aircraft->metadata, 'maintenance.a_check_baseline_hours'));
        $this->assertSame(405, (int) data_get($aircraft->metadata, 'maintenance.a_check_baseline_cycles'));

        $this->assertDatabaseHas('ledger_accounts', [
            'airline_id' => $airline->id,
            'code' => 'MAINTENANCE_EXPENSE',
        ]);
        $this->assertDatabaseHas('ledger_transactions', [
            'airline_id' => $airline->id,
            'reference_type' => 'aircraft_maintenance',
            'reference_id' => $event->id,
        ]);

        $transactionsBefore = LedgerTransaction::query()
            ->where('reference_type', 'aircraft_maintenance')
            ->count();
        $maintenance->processWorld($world, $event->planned_end_at->copy()->addHours(2));
        $transactionsAfter = LedgerTransaction::query()
            ->where('reference_type', 'aircraft_maintenance')
            ->count();
        $this->assertSame($transactionsBefore, $transactionsAfter);

        $this->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('D-ATCH')
            ->assertSee('A-Check')
            ->assertSee('Abgeschlossen');
    }
}
