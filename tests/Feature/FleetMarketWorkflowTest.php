<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class FleetMarketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_airline_can_lease_and_buy_finite_used_aircraft_with_delayed_delivery(): void
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
        $frankfurt = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $this->post(route('worlds.enter', $world));
        $this->post('/airline', [
            'name' => 'Fleet Air',
            'home_airport_id' => $frankfurt->id,
            'iata_code' => 'FA',
            'icao_code' => 'FLT',
            'callsign' => 'FLEET AIR',
            'business_model' => 'hybrid',
            'service_concept' => 'balanced',
            'target_group' => 'mixed',
        ]);

        $airline = Airline::query()->where('name', 'Fleet Air')->firstOrFail();
        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();
        $service = app(ProcurementService::class);

        $cashBeforeLease = $service->cashBalanceMinor($airline);
        $this->post(route('fleet-market.lease'), [
            'aircraft_type_id' => $type->id,
            'registration' => 'D-AFLE',
            'lease_term_months' => 60,
        ])->assertRedirect('/fleet-market');

        $lease = AircraftProcurement::query()->where('registration', 'D-AFLE')->firstOrFail();
        $this->assertSame('lease', $lease->procurement_type);
        $this->assertSame('ordered', $lease->status);
        $this->assertGreaterThan(0, $lease->monthly_payment_minor);
        $this->assertLessThan($cashBeforeLease, $service->cashBalanceMinor($airline));
        $this->assertDatabaseMissing('aircraft', ['registration' => 'D-AFLE']);

        $service->processWorld($world, $lease->delivery_due_at->copy()->addMinute());
        $lease->refresh();
        $this->assertSame('delivered', $lease->status);
        $this->assertNotNull($lease->next_payment_at);
        $this->assertDatabaseHas('aircraft', ['registration' => 'D-AFLE', 'ownership_type' => 'leased']);

        $cashBeforeRate = $service->cashBalanceMinor($airline);
        $paymentAt = $lease->next_payment_at->copy();
        $summary = $service->processWorld($world, $paymentAt->copy()->addMinute());
        $this->assertSame(1, $summary['lease_payments']);
        $this->assertLessThan($cashBeforeRate, $service->cashBalanceMinor($airline));
        $this->assertSame(1, LedgerTransaction::query()->where('reference_type', 'aircraft_lease_payment')->count());

        $offer = AircraftMarketOffer::query()->where('world_id', $world->id)->where('status', 'available')->orderBy('price_minor')->firstOrFail();
        $originalHours = (float) $offer->flight_hours;
        $originalCondition = (float) $offer->condition_percent;

        $this->post(route('fleet-market.used', $offer), [
            'registration' => 'D-AUSE',
        ])->assertRedirect('/fleet-market');

        $offer->refresh();
        $this->assertSame('reserved', $offer->status);
        $used = AircraftProcurement::query()->where('registration', 'D-AUSE')->firstOrFail();
        $this->assertSame('purchase_used', $used->procurement_type);

        $service->processWorld($world, $used->delivery_due_at->copy()->addMinute());
        $offer->refresh();
        $used->refresh();
        $aircraft = $used->deliveredAircraft()->firstOrFail();

        $this->assertSame('sold', $offer->status);
        $this->assertSame('delivered', $used->status);
        $this->assertSame('owned', $aircraft->ownership_type);
        $this->assertSame($originalHours, (float) $aircraft->flight_hours);
        $this->assertSame($originalCondition, (float) $aircraft->condition_percent);

        $this->get(route('fleet-market.index'))
            ->assertOk()
            ->assertSee('Flottenmarkt')
            ->assertSee('Bestellungen & Verträge')
            ->assertSee('D-AFLE')
            ->assertSee('D-AUSE');
    }
}
