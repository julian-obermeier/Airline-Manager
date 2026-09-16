<?php

namespace App\Http\Controllers;

use App\Models\World;
use App\Services\Operations\MaintenanceService;
use App\Services\Operations\ProcurementService;
use App\Services\Simulation\FlightSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function __invoke(
        Request $request,
        FlightSimulationService $simulation,
        MaintenanceService $maintenance,
        ProcurementService $procurement,
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
        $procurementSummary = [
            'deliveries' => 0,
            'lease_payments' => 0,
            'leases_ended' => 0,
        ];

        World::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (World $world) use ($maintenance, $procurement, &$maintenanceSummary, &$procurementSummary): void {
                $world->refresh();
                $simulationNow = $world->simulated_at ?? now();

                $maintenanceResult = $maintenance->processWorld($world, $simulationNow);
                foreach ($maintenanceSummary as $key => $value) {
                    $maintenanceSummary[$key] += (int) ($maintenanceResult[$key] ?? 0);
                }

                $procurementResult = $procurement->processWorld($world, $simulationNow);
                foreach ($procurementSummary as $key => $value) {
                    $procurementSummary[$key] += (int) ($procurementResult[$key] ?? 0);
                }
            });

        return response()->json([
            'status' => 'ok',
            'tick' => $tick,
            'maintenance' => $maintenanceSummary,
            'procurement' => $procurementSummary,
            'executed_at' => now()->toIso8601String(),
        ]);
    }
}
