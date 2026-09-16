<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\World;
use Illuminate\Database\Seeder;

class GameBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        World::query()->updateOrCreate(
            ['slug' => 'europa-1'],
            [
                'name' => 'Europa 1',
                'type' => 'persistent',
                'status' => 'active',
                'speed_multiplier' => 1.00,
                'simulated_at' => now(),
                'starts_at' => now(),
                'ends_at' => null,
                'random_seed' => 26091601,
                'settings' => [
                    'currency' => 'EUR',
                    'starting_capital_minor' => 5000000000,
                    'region' => 'Europe',
                ],
            ]
        );

        $airports = [
            [
                'icao_code' => 'EDDF', 'iata_code' => 'FRA', 'name' => 'Frankfurt Airport',
                'city' => 'Frankfurt am Main', 'country_code' => 'DE',
                'latitude' => 50.037900, 'longitude' => 8.562200, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 364,
            ],
            [
                'icao_code' => 'EDDM', 'iata_code' => 'MUC', 'name' => 'Munich Airport',
                'city' => 'München', 'country_code' => 'DE',
                'latitude' => 48.353800, 'longitude' => 11.786100, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 1487,
            ],
            [
                'icao_code' => 'EDDB', 'iata_code' => 'BER', 'name' => 'Berlin Brandenburg Airport',
                'city' => 'Berlin', 'country_code' => 'DE',
                'latitude' => 52.366700, 'longitude' => 13.503300, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 157,
            ],
            [
                'icao_code' => 'EDDL', 'iata_code' => 'DUS', 'name' => 'Düsseldorf Airport',
                'city' => 'Düsseldorf', 'country_code' => 'DE',
                'latitude' => 51.289500, 'longitude' => 6.766800, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 147,
            ],
            [
                'icao_code' => 'EDDH', 'iata_code' => 'HAM', 'name' => 'Hamburg Airport',
                'city' => 'Hamburg', 'country_code' => 'DE',
                'latitude' => 53.630400, 'longitude' => 9.988200, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 53,
            ],
            [
                'icao_code' => 'EDDK', 'iata_code' => 'CGN', 'name' => 'Cologne Bonn Airport',
                'city' => 'Köln/Bonn', 'country_code' => 'DE',
                'latitude' => 50.865900, 'longitude' => 7.142700, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 302,
            ],
            [
                'icao_code' => 'LOWW', 'iata_code' => 'VIE', 'name' => 'Vienna International Airport',
                'city' => 'Wien', 'country_code' => 'AT',
                'latitude' => 48.110300, 'longitude' => 16.569700, 'timezone' => 'Europe/Vienna', 'elevation_ft' => 600,
            ],
            [
                'icao_code' => 'LSZH', 'iata_code' => 'ZRH', 'name' => 'Zurich Airport',
                'city' => 'Zürich', 'country_code' => 'CH',
                'latitude' => 47.458100, 'longitude' => 8.555500, 'timezone' => 'Europe/Zurich', 'elevation_ft' => 1416,
            ],
            [
                'icao_code' => 'EHAM', 'iata_code' => 'AMS', 'name' => 'Amsterdam Airport Schiphol',
                'city' => 'Amsterdam', 'country_code' => 'NL',
                'latitude' => 52.308600, 'longitude' => 4.763900, 'timezone' => 'Europe/Amsterdam', 'elevation_ft' => -11,
            ],
            [
                'icao_code' => 'EGLL', 'iata_code' => 'LHR', 'name' => 'London Heathrow Airport',
                'city' => 'London', 'country_code' => 'GB',
                'latitude' => 51.470000, 'longitude' => -0.454300, 'timezone' => 'Europe/London', 'elevation_ft' => 83,
            ],
        ];

        foreach ($airports as $airport) {
            Airport::query()->updateOrCreate(
                ['icao_code' => $airport['icao_code']],
                $airport + [
                    'passenger_capacity_yearly' => null,
                    'cargo_capacity_tonnes_yearly' => null,
                    'runways' => null,
                    'operational_restrictions' => null,
                    'metadata' => ['bootstrap' => true],
                ]
            );
        }
    }
}
