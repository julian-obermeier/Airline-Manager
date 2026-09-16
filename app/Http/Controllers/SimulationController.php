<?php

namespace App\Http\Controllers;

use App\Models\World;
use App\Services\Operations\MaintenanceService;
use App\Services\Simulation\FlightSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function __invoke(
        Request $request,
        FlightSimulationService $simulation,
        MaintenanceService $maintenance,
    ): JsonResponse {
        $configuredToken = (string) config('simulation.cron_token');
        $providedToken = (string) $request->query('token');

        abort_if($configuredToken === '' || ! hash_equals($configuredToken, $providedToken), 403);

        $tick = $simulation->tick();
        $maintenanceSummary = [
            'maintenance_started' => 0,
            'maintenance_completed' => 0,
            'maintenance_grounded' => 0,
            'maintenance_cancelled_flights' => 0,
        ];

        World::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (World $world) use ($maintenance, &$maintenanceSummary): void {
                $world->refresh();
                $result = $maintenance->processWorld($world, $world->simulated_at ?? now());

                foreach ($maintenanceSummary as $key => $value) {
                    $maintenanceSummary[$key] += (int) ($result[$key] ?? 0);
                }
            });

        return response()->json([
            'status' => 'ok',
            'tick' => $tick,
            'maintenance' => $maintenanceSummary,
            'executed_at' => now()->toIso8601String(),
        ]);
    }
}
