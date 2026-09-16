<?php

namespace Tests\Feature;

use App\Models\AircraftProcurement;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\World;
use App\Services\Operations\ProcurementService;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_center_aggregates_ledger_and_lease_commitments(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Finance Manager',
            'username' => 'financemanager',
            'email' => 'finance@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Finance Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'FA',
            'icao_code' => 'FNC',
            'callsign' => 'FINANCE',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Finance Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();

        $initial = $this->get(route('finance.index', ['days' => 30]));
        $initial->assertOk()
            ->assertSee('Finanzzentrum')
            ->assertSee('Bankguthaben')
            ->assertSee('Startkapital bei Airline-Gründung');

        $this->post(route('fleet-market.lease'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AFIN',
            'lease_term_months' => 60,
        ])->assertRedirect('/fleet-market');

        $procurement = AircraftProcurement::query()->where('registration', 'D-AFIN')->firstOrFail();
        app(ProcurementService::class)->processWorld($world, $procurement->delivery_due_at->copy()->addMinute());

        $response = $this->get(route('finance.index', ['days' => 30]));
        $response->assertOk()
            ->assertSee('Leasingverpflichtungen')
            ->assertSee('D-AFIN')
            ->assertSee('Leasing-Bereitstellungsgebühr')
            ->assertSee('LEASE_EXPENSE');

        $response->assertViewHas('cashBalanceMinor', fn (int $value): bool => $value < (int) $airline->starting_capital_minor);
        $response->assertViewHas('monthlyLeaseCommitmentMinor', fn (int $value): bool => $value > 0);
        $response->assertViewHas('expenseMinor', fn (int $value): bool => $value > 0);
    }
}
