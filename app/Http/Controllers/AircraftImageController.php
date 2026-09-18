<?php

namespace App\Http\Controllers;

use App\Models\AircraftType;
use App\Services\Media\AircraftImageService;
use Illuminate\Http\JsonResponse;

class AircraftImageController extends Controller
{
    public function __invoke(
        AircraftType $aircraftType,
        AircraftImageService $images
    ): JsonResponse {
        $image = $images->resolve($aircraftType);

        if (! $image) {
            return response()->json([
                'found' => false,
                'aircraft' => trim($aircraftType->manufacturer.' '.$aircraftType->model),
            ], 404, [
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        return response()->json([
            'found' => true,
            ...$image,
        ], 200, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
