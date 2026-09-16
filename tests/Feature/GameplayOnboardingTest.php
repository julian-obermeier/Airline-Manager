<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\World;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameplayOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_enter_world_and_create_airline(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Test Pilot',
            'username' => 'testpilot',
            'email' => 'pilot@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ])->assertRedirect('/worlds');

        $this->assertAuthenticated();

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $airport = Airport::query()->where('iata_code', 'FRA')->firstOrFail();

        $this->post(route('worlds.enter', $world))
            ->assertRedirect('/airline/create');

        $this->post('/airline', [
            'name' => 'Test Air',
            'home_airport_id' => $airport->id,
            'iata_code' => 'TA',
            'icao_code' => 'TST',
            'callsign' => 'TEST AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('airlines', [
            'world_id' => $world->id,
            'name' => 'Test Air',
            'iata_code' => 'TA',
            'icao_code' => 'TST',
        ]);

        $this->assertDatabaseHas('ledger_accounts', ['code' => 'CASH']);
        $this->assertDatabaseCount('ledger_entries', 2);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Test Air')
            ->assertSee('Frankfurt Airport');
    }
}
