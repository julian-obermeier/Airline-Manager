<?php

namespace Tests\Feature;

use App\Models\AircraftType;
use Database\Seeders\GameBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AircraftInternetImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_aircraft_image_endpoint_returns_real_commons_photo_metadata(): void
    {
        $this->seed(GameBootstrapSeeder::class);

        $this->post('/register', [
            'name' => 'Photo Tester',
            'username' => 'phototester',
            'email' => 'photos@example.test',
            'password' => 'Airline2026Test',
            'password_confirmation' => 'Airline2026Test',
        ]);

        $type = AircraftType::query()->where('model', 'A220')->firstOrFail();

        Http::preventStrayRequests();
        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [[
                        'pageid' => 123,
                        'ns' => 6,
                        'title' => 'File:Airbus A220-300 test aircraft.jpg',
                        'index' => 1,
                        'imageinfo' => [[
                            'thumburl' => 'https://upload.wikimedia.org/test/Airbus_A220-300.jpg',
                            'url' => 'https://upload.wikimedia.org/test/original_Airbus_A220-300.jpg',
                            'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Airbus_A220-300_test_aircraft.jpg',
                            'width' => 2400,
                            'height' => 1600,
                            'mime' => 'image/jpeg',
                            'extmetadata' => [
                                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                                'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
                                'Artist' => ['value' => '<a href="#">Test Photographer</a>'],
                                'Credit' => ['value' => 'Own work'],
                            ],
                        ]],
                    ]],
                ],
            ], 200),
        ]);

        $this->get(route('aircraft-images.show', $type))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('provider', 'Wikimedia Commons')
            ->assertJsonPath('license', 'CC BY-SA 4.0')
            ->assertJsonPath('author', 'Test Photographer')
            ->assertJsonPath('image_url', 'https://upload.wikimedia.org/test/Airbus_A220-300.jpg')
            ->assertJsonPath('source_url', 'https://commons.wikimedia.org/wiki/File:Airbus_A220-300_test_aircraft.jpg');
    }
}
