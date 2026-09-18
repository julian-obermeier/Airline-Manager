<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\LedgerTransaction;
use App\Models\MarketingCampaign;
use App\Models\World;
use App\Services\Commercial\MarketingService;
use App\Services\Simulation\FlightSimulationService;
use Carbon\Carbon;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_change_awareness_demand_reputation_and_cash(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Marketing Manager',
            'username' => 'marketingmanager',
            'email' => 'marketing@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $world = World::query()->where('slug', 'europa-1')->firstOrFail();
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $munich = Airport::query()->where('iata_code', 'MUC')->firstOrFail();

        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Market Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'MK',
            'icao_code' => 'MKT',
            'callsign' => 'MARKET',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Market Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'E195-E2')->firstOrFail();

        $aircraft = Aircraft::create([
            'world_id' => $airline->world_id,
            'airline_id' => $airline->id,
            'aircraft_type_id' => $type->id,
            'current_airport_id' => $frankfurt->id,
            'registration' => 'D-AMKT',
            'flight_hours' => 0,
            'flight_cycles' => 0,
            'condition_percent' => 100,
            'status' => 'available',
            'ownership_type' => 'owned',
            'acquisition_price_minor' => 1000000000,
            'currency' => 'EUR',
            'configuration' => [
                'seats' => 132,
                'cabins' => ['economy' => 118, 'business' => 14, 'first' => 0],
            ],
            'metadata' => [],
        ]);

        $this->post(route('operations.routes.store'), [
            'origin_airport_id' => $frankfurt->id,
            'destination_airport_id' => $munich->id,
        ])->assertRedirect(route('operations.index'));

        $route = $airline->routes()->firstOrFail();
        $departure = now()->addDays(2)->setTime(10, 0, 0);

        $this->post(route('operations.flights.store'), [
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => 'MK101',
            'scheduled_departure_at' => $departure->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('operations.index'));

        $flight = Flight::query()->where('flight_number', 'MK101')->firstOrFail();
        $marketing = app(MarketingService::class);
        $profileBefore = $marketing->ensureProfile($airline)->fresh();
        $routeMetricBefore = $marketing->ensureRouteMetric($route)->fresh();
        $cashBefore = $marketing->cashBalanceMinor($airline);
        $snapshotBefore = $marketing->demandSnapshot($flight, $world->simulated_at ?? now());

        $this->post(route('marketing.store'), [
            'name' => 'München Push',
            'scope' => 'route',
            'route_id' => $route->id,
            'channel' => 'search',
            'budget' => 100000,
            'duration_days' => 14,
        ])->assertRedirect(route('marketing.index'));

        $campaign = MarketingCampaign::query()->where('name', 'München Push')->firstOrFail();
        $profileAfter = $profileBefore->fresh();
        $routeMetricAfter = $routeMetricBefore->fresh();
        $cashAfter = $marketing->cashBalanceMinor($airline);
        $snapshotAfter = $marketing->demandSnapshot($flight, $world->simulated_at ?? now());

        $this->assertSame(10000000, (int) $campaign->budget_minor);
        $this->assertSame('active', $campaign->status);
        $this->assertSame($cashBefore - 10000000, $cashAfter);
        $this->assertGreaterThan((float) $profileBefore->awareness_score, (float) $profileAfter->awareness_score);
        $this->assertGreaterThan((float) $routeMetricBefore->awareness_score, (float) $routeMetricAfter->awareness_score);
        $this->assertGreaterThan((float) $snapshotBefore['multiplier'], (float) $snapshotAfter['multiplier']);
        $this->assertGreaterThan(0, (float) $snapshotAfter['campaign_boost']);

        $this->assertSame(
            1,
            LedgerTransaction::query()
                ->where('airline_id', $airline->id)
                ->where('reference_type', 'marketing_campaign')
                ->where('reference_id', $campaign->id)
                ->count()
        );

        $simulationAt = Carbon::parse($departure)->subHours(12);
        app(FlightSimulationService::class)->tick($simulationAt);

        $flight->refresh();
        $this->assertGreaterThan(0, $flight->passengers_booked);
        $this->assertGreaterThan(0, (float) data_get($flight->operational_data, 'commercial.marketing.campaign_boost'));
        $this->assertNotEmpty(data_get($flight->operational_data, 'commercial.marketing.active_campaigns'));

        $data = $flight->operational_data ?? [];
        $data['load_factor'] = 0.84;
        $flight->forceFill([
            'delay_minutes' => 0,
            'actual_arrival_at' => $flight->scheduled_arrival_at,
            'operational_data' => $data,
        ])->save();

        $reputationBefore = (float) $profileAfter->fresh()->reputation_score;
        $marketing->recordCompletedFlight($flight->fresh());

        $profileCompleted = $profileAfter->fresh();
        $routeMetricCompleted = $routeMetricAfter->fresh();

        $this->assertSame(1, (int) $profileCompleted->completed_flights);
        $this->assertSame(1, (int) $routeMetricCompleted->completed_flights);
        $this->assertGreaterThan($reputationBefore, (float) $profileCompleted->reputation_score);
        $this->assertGreaterThan(0, (int) $profileCompleted->brand_value_minor);
        $this->assertEqualsWithDelta(0.84, (float) $routeMetricCompleted->historical_load_factor, 0.0001);

        $cashBeforeCancel = $marketing->cashBalanceMinor($airline);
        $this->patch(route('marketing.cancel', $campaign))->assertRedirect(route('marketing.index'));

        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame($cashBeforeCancel, $marketing->cashBalanceMinor($airline));

        $this->get(route('marketing.index'))
            ->assertOk()
            ->assertSee('Marketing & Reputation')
            ->assertSee('München Push')
            ->assertSee('FRA')
            ->assertSee('MUC');
    }
}
