<?php

namespace App\Http\Controllers;

use App\Services\Simulation\FlightSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function __invoke(Request $request, FlightSimulationService $simulation): JsonResponse
    {
        $configuredToken = (string) config('simulation.cron_token');
        $providedToken = (string) $request->query('token');

        abort_if($configuredToken === '' || ! hash_equals($configuredToken, $providedToken), 403);

        return response()->json([
            'status' => 'ok',
            'tick' => $simulation->tick(),
            'executed_at' => now()->toIso8601String(),
        ]);
    }
}
