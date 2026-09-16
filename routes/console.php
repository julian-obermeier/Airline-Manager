<?php

use App\Models\World;
use App\Services\Operations\FlightLocationGuardService;
use App\Services\Operations\MaintenanceService;
use App\Services\Operations\ProcurementService;
use App\Services\Simulation\FlightSimulationService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('airline:status', function (): void {
    $this->info('Airline Empire foundation is available.');
})->purpose('Show the current application bootstrap status');

Artisan::command('airline:simulate', function (
    FlightSimulationService $simulation,
    MaintenanceService $maintenance,
    ProcurementService $procurement,
    FlightLocationGuardService $locationGuard,
): void {
    $realNow = now();
    $locationSummary = $locationGuard->guardBeforeTick($realNow);
    $summary = $simulation->tick($realNow);
    $maintenanceSummary = ['maintenance_started' => 0, 'maintenance_completed' => 0, 'maintenance_grounded' => 0];
    $procurementSummary = ['deliveries' => 0, 'lease_payments' => 0, 'leases_ended' => 0];

    World::query()->where('status', 'active')->orderBy('id')->each(function (World $world) use ($maintenance, $procurement, &$maintenanceSummary, &$procurementSummary): void {
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

    $this->info('Simulation tick completed.');
    $this->table(
        ['Worlds', 'Checked', 'Boarding', 'Departed', 'In air', 'Completed', 'Location blocks', 'Maintenance', 'Deliveries', 'Lease payments'],
        [[
            $summary['worlds'],
            $summary['flights_checked'],
            $summary['boarding'],
            $summary['departed'],
            $summary['in_air'],
            $summary['completed'],
            $locationSummary['cancelled_location'],
            $maintenanceSummary['maintenance_started'] + $maintenanceSummary['maintenance_completed'],
            $procurementSummary['deliveries'],
            $procurementSummary['lease_payments'],
        ]]
    );
})->purpose('Advance world clocks and process flights, location integrity, maintenance, deliveries and leasing');
