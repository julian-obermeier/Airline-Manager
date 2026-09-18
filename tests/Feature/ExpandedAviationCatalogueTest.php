<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use App\Models\Airport;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpandedAviationCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_contains_expanded_airport_and_aircraft_catalogues(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->assertSame(110, Airport::query()->count());
        $this->assertSame(29, AircraftType::query()->count());

        foreach (['FRA', 'LHR', 'CDG', 'MAD', 'IST', 'DXB', 'JFK', 'LAX', 'YYZ'] as $iata) {
            $this->assertTrue(
                Airport::query()->where('iata_code', $iata)->exists(),
                'Expected airport '.$iata.' to exist.'
            );
        }

        foreach ([
            ['Airbus', 'A321XLR'],
            ['Airbus', 'A350-900'],
            ['Boeing', '787-9'],
            ['Boeing', '777-300ER'],
            ['ATR', 'ATR 72-600'],
            ['De Havilland Canada', 'Dash 8-400'],
        ] as [$manufacturer, $model]) {
            $this->assertTrue(
                AircraftType::query()
                    ->where('manufacturer', $manufacturer)
                    ->where('model', $model)
                    ->exists(),
                'Expected aircraft type '.$manufacturer.' '.$model.' to exist.'
            );
        }

        $this->assertSame(
            100,
            collect(require database_path('seeders/data/airports.php'))->count()
        );
        $this->assertSame(
            25,
            collect(require database_path('seeders/data/aircraft_types.php'))->count()
        );
    }
}
