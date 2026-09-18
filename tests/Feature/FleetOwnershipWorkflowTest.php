<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftMarketOffer;
use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\LedgerTransaction;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetOwnershipWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_aircraft_can_be_reviewed_and_sold_into_used_market(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Fleet Manager',
            'username' => 'fleetmanager',
            'email' => 'fleet@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Fleet Air',
            'home_airport_id' => $fra->id,
            'iata_code' => 'FM',
            'icao_code' => 'FLT',
            'callsign' => 'FLEET AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Fleet Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();

        $this->post(route('fleet-market.new'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AFLT',
        ])->assertRedirect(route('fleet-market.index'));

        $procurement = AircraftProcurement::query()->where('registration', 'D-AFLT')->firstOrFail();
        app(ProcurementService::class)->processWorld(
            $world,
            $procurement->delivery_due_at->copy()->addMinute()
        );

        $aircraft = Aircraft::query()->where('registration', 'D-AFLT')->firstOrFail();

        $this->get(route('fleet.index'))
            ->assertOk()
            ->assertSee('Meine Flotte')
            ->assertSee('D-AFLT')
            ->assertSee('Flugzeug verkaufen');

        $cashBefore = (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');

        $this->post(route('fleet.sell', $aircraft))
            ->assertRedirect(route('fleet.index'));

        $aircraft->refresh();
        $this->assertNull($aircraft->airline_id);
        $this->assertSame('sold', $aircraft->status);

        $cashAfter = (int) DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.airline_id', $airline->id)
            ->where('ledger_accounts.code', 'CASH')
            ->sum('ledger_entries.amount_minor');

        $this->assertGreaterThan($cashBefore, $cashAfter);

        $this->assertTrue(
            LedgerTransaction::query()
                ->where('airline_id', $airline->id)
                ->where('reference_type', 'aircraft_sale')
                ->where('reference_id', $aircraft->id)
                ->exists()
        );

        $this->assertTrue(
            AircraftMarketOffer::query()
                ->where('world_id', $world->id)
                ->where('serial_number', $aircraft->serial_number)
                ->where('status', 'available')
                ->exists()
        );

        $this->get(route('fleet.index'))
            ->assertOk()
            ->assertViewHas('fleetRows', fn ($rows): bool => $rows->isEmpty());
    }
}
