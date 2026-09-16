<?php

namespace Tests\Feature;

use App\Models\Airline;
use App\Models\Airport;
use App\Models\World;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorldMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_map_renders_airports_and_airline_routes(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Map Manager',
            'username' => 'mapmanager',
            'email' => 'map@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $london = Airport::query()->where('iata_code', 'LHR')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Map Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'MA',
            'icao_code' => 'MAP',
            'callsign' => 'MAP AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Map Air')->firstOrFail();

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $london->id,
        ])->assertRedirect('/operations');

        $response = $this->get(route('map.index'));

        $response->assertOk()
            ->assertSee('Weltkarte')
            ->assertSee('FRA')
            ->assertSee('LHR')
            ->assertSeeText('Frankfurt am Main → London');

        $response->assertViewHas('mapRoutes', fn ($routes): bool => $routes->count() === 1);
        $response->assertViewHas('mapAirports', fn ($airports): bool => $airports->count() >= 2);
        $response->assertViewHas('airline', fn (Airline $viewAirline): bool => $viewAirline->is($airline));
    }
}
