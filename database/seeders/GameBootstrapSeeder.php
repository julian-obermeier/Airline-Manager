<?php

namespace Database\Seeders;

use App\Models\AircraftMarketOffer;
use App\Models\AircraftType;
use App\Models\Airport;
use App\Models\World;
use Illuminate\Database\Seeder;

class GameBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $world = World::query()->firstOrCreate(
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
            ['icao_code' => 'EDDF', 'iata_code' => 'FRA', 'name' => 'Frankfurt Airport', 'city' => 'Frankfurt am Main', 'country_code' => 'DE', 'latitude' => 50.037900, 'longitude' => 8.562200, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 364],
            ['icao_code' => 'EDDM', 'iata_code' => 'MUC', 'name' => 'Munich Airport', 'city' => 'München', 'country_code' => 'DE', 'latitude' => 48.353800, 'longitude' => 11.786100, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 1487],
            ['icao_code' => 'EDDB', 'iata_code' => 'BER', 'name' => 'Berlin Brandenburg Airport', 'city' => 'Berlin', 'country_code' => 'DE', 'latitude' => 52.366700, 'longitude' => 13.503300, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 157],
            ['icao_code' => 'EDDL', 'iata_code' => 'DUS', 'name' => 'Düsseldorf Airport', 'city' => 'Düsseldorf', 'country_code' => 'DE', 'latitude' => 51.289500, 'longitude' => 6.766800, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 147],
            ['icao_code' => 'EDDH', 'iata_code' => 'HAM', 'name' => 'Hamburg Airport', 'city' => 'Hamburg', 'country_code' => 'DE', 'latitude' => 53.630400, 'longitude' => 9.988200, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 53],
            ['icao_code' => 'EDDK', 'iata_code' => 'CGN', 'name' => 'Cologne Bonn Airport', 'city' => 'Köln/Bonn', 'country_code' => 'DE', 'latitude' => 50.865900, 'longitude' => 7.142700, 'timezone' => 'Europe/Berlin', 'elevation_ft' => 302],
            ['icao_code' => 'LOWW', 'iata_code' => 'VIE', 'name' => 'Vienna International Airport', 'city' => 'Wien', 'country_code' => 'AT', 'latitude' => 48.110300, 'longitude' => 16.569700, 'timezone' => 'Europe/Vienna', 'elevation_ft' => 600],
            ['icao_code' => 'LSZH', 'iata_code' => 'ZRH', 'name' => 'Zurich Airport', 'city' => 'Zürich', 'country_code' => 'CH', 'latitude' => 47.458100, 'longitude' => 8.555500, 'timezone' => 'Europe/Zurich', 'elevation_ft' => 1416],
            ['icao_code' => 'EHAM', 'iata_code' => 'AMS', 'name' => 'Amsterdam Airport Schiphol', 'city' => 'Amsterdam', 'country_code' => 'NL', 'latitude' => 52.308600, 'longitude' => 4.763900, 'timezone' => 'Europe/Amsterdam', 'elevation_ft' => -11],
            ['icao_code' => 'EGLL', 'iata_code' => 'LHR', 'name' => 'London Heathrow Airport', 'city' => 'London', 'country_code' => 'GB', 'latitude' => 51.470000, 'longitude' => -0.454300, 'timezone' => 'Europe/London', 'elevation_ft' => 83],
        ];

        $slotCapacities = [
            'FRA' => 20,
            'MUC' => 18,
            'BER' => 12,
            'DUS' => 12,
            'HAM' => 10,
            'CGN' => 10,
            'VIE' => 14,
            'ZRH' => 12,
            'AMS' => 20,
            'LHR' => 18,
        ];

        foreach ($airports as $airport) {
            Airport::query()->updateOrCreate(
                ['icao_code' => $airport['icao_code']],
                $airport + [
                    'passenger_capacity_yearly' => null,
                    'cargo_capacity_tonnes_yearly' => null,
                    'runways' => null,
                    'operational_restrictions' => null,
                    'metadata' => [
                        'bootstrap' => true,
                        'slot_capacity_15min' => $slotCapacities[$airport['iata_code']] ?? 12,
                    ],
                ]
            );
        }

        $aircraftTypes = [
            ['manufacturer' => 'Airbus', 'model' => 'A220', 'variant' => 'A220-300', 'icao_type_code' => 'BCS3', 'typical_seats' => 137, 'max_seats' => 160, 'range_km' => 6297, 'cruise_speed_kmh' => 829, 'minimum_runway_m' => 1890, 'max_payload_kg' => 18450, 'fuel_capacity_l' => 21805, 'reference_purchase_price_minor' => 3500000000, 'fuel_burn_l_per_hour' => 2100],
            ['manufacturer' => 'Embraer', 'model' => 'E195-E2', 'variant' => 'E195-E2', 'icao_type_code' => 'E295', 'typical_seats' => 132, 'max_seats' => 146, 'range_km' => 4815, 'cruise_speed_kmh' => 870, 'minimum_runway_m' => 1800, 'max_payload_kg' => 16100, 'fuel_capacity_l' => 17100, 'reference_purchase_price_minor' => 3200000000, 'fuel_burn_l_per_hour' => 2200],
            ['manufacturer' => 'Airbus', 'model' => 'A320neo', 'variant' => 'A320-251N', 'icao_type_code' => 'A20N', 'typical_seats' => 180, 'max_seats' => 194, 'range_km' => 6300, 'cruise_speed_kmh' => 840, 'minimum_runway_m' => 1950, 'max_payload_kg' => 19000, 'fuel_capacity_l' => 26730, 'reference_purchase_price_minor' => 5500000000, 'fuel_burn_l_per_hour' => 2500],
            ['manufacturer' => 'Boeing', 'model' => '737 MAX 8', 'variant' => '737-8', 'icao_type_code' => 'B38M', 'typical_seats' => 178, 'max_seats' => 210, 'range_km' => 6570, 'cruise_speed_kmh' => 842, 'minimum_runway_m' => 2100, 'max_payload_kg' => 20200, 'fuel_capacity_l' => 25816, 'reference_purchase_price_minor' => 5200000000, 'fuel_burn_l_per_hour' => 2600],
        ];

        foreach ($aircraftTypes as $type) {
            $fuelBurn = $type['fuel_burn_l_per_hour'];
            unset($type['fuel_burn_l_per_hour']);

            AircraftType::query()->updateOrCreate(
                ['manufacturer' => $type['manufacturer'], 'model' => $type['model'], 'variant' => $type['variant']],
                $type + [
                    'reference_currency' => 'EUR',
                    'production_status' => 'active',
                    'technical_data' => [
                        'bootstrap' => true,
                        'pricing' => 'game_reference',
                        'fuel_burn_l_per_hour' => $fuelBurn,
                    ],
                ]
            );
        }

        $fra = Airport::query()->where('iata_code', 'FRA')->firstOrFail();
        $muc = Airport::query()->where('iata_code', 'MUC')->firstOrFail();
        $ams = Airport::query()->where('iata_code', 'AMS')->firstOrFail();

        $usedOffers = [
            ['serial_number' => 'USED-E295-001', 'model' => 'E195-E2', 'airport_id' => $fra->id, 'years' => 4, 'hours' => 8120, 'cycles' => 5240, 'condition' => 92.4, 'price' => 2150000000],
            ['serial_number' => 'USED-BCS3-001', 'model' => 'A220', 'airport_id' => $muc->id, 'years' => 5, 'hours' => 10350, 'cycles' => 6880, 'condition' => 89.8, 'price' => 2280000000],
            ['serial_number' => 'USED-A20N-001', 'model' => 'A320neo', 'airport_id' => $ams->id, 'years' => 6, 'hours' => 14750, 'cycles' => 7430, 'condition' => 87.6, 'price' => 3380000000],
            ['serial_number' => 'USED-B38M-001', 'model' => '737 MAX 8', 'airport_id' => $fra->id, 'years' => 4, 'hours' => 9850, 'cycles' => 5710, 'condition' => 91.1, 'price' => 3520000000],
        ];

        foreach ($usedOffers as $offer) {
            $type = AircraftType::query()->where('model', $offer['model'])->firstOrFail();
            AircraftMarketOffer::query()->firstOrCreate(
                ['world_id' => $world->id, 'serial_number' => $offer['serial_number']],
                [
                    'aircraft_type_id' => $type->id,
                    'location_airport_id' => $offer['airport_id'],
                    'manufactured_on' => now()->subYears($offer['years'])->toDateString(),
                    'flight_hours' => $offer['hours'],
                    'flight_cycles' => $offer['cycles'],
                    'condition_percent' => $offer['condition'],
                    'price_minor' => $offer['price'],
                    'currency' => 'EUR',
                    'status' => 'available',
                    'metadata' => ['source' => 'bootstrap_used_market'],
                ]
            );
        }
    }
}
