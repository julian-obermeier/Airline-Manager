<?php

use App\Models\Airline;
use App\Models\World;
use App\Services\Commercial\MarketCompetitionService;
use App\Services\Commercial\MarketingService;
use App\Services\Operations\AirportOperationsService;
use App\Services\Operations\CrewService;
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
    CrewService $crewService,
    AirportOperationsService $airportOperations,
): void {
    $realNow = now();
    $locationSummary = $locationGuard->guardBeforeTick($realNow);
    $summary = $simulation->tick($realNow);
    $maintenanceSummary = ['maintenance_started' => 0, 'maintenance_completed' => 0, 'maintenance_grounded' => 0];
    $procurementSummary = ['deliveries' => 0, 'lease_payments' => 0, 'leases_ended' => 0];
    $crewSummary = ['payroll_runs' => 0, 'salary_minor' => 0];
    $airportSummary = ['station_fee_runs' => 0, 'station_cost_minor' => 0];

    World::query()->where('status', 'active')->orderBy('id')->each(function (World $world) use ($maintenance, $procurement, $crewService, $airportOperations, &$maintenanceSummary, &$procurementSummary, &$crewSummary, &$airportSummary): void {
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

        $payrollResult = $crewService->processPayroll($world, $simulationNow);
        foreach ($crewSummary as $key => $value) {
            $crewSummary[$key] += (int) ($payrollResult[$key] ?? 0);
        }

        $airportResult = $airportOperations->processStationFees($world, $simulationNow);
        foreach ($airportSummary as $key => $value) {
            $airportSummary[$key] += (int) ($airportResult[$key] ?? 0);
        }
    });

    $this->info('Simulation tick completed.');
    $this->table(
        ['Worlds', 'Checked', 'Boarding', 'Departed', 'In air', 'Completed', 'Crew blocks', 'Location blocks', 'Maintenance', 'Deliveries', 'Lease payments', 'Payroll', 'Station fees'],
        [[
            $summary['worlds'],
            $summary['flights_checked'],
            $summary['boarding'],
            $summary['departed'],
            $summary['in_air'],
            $summary['completed'],
            $summary['crew_cancelled'],
            $locationSummary['cancelled_location'],
            $maintenanceSummary['maintenance_started'] + $maintenanceSummary['maintenance_completed'],
            $procurementSummary['deliveries'],
            $procurementSummary['lease_payments'],
            $crewSummary['payroll_runs'],
            $airportSummary['station_fee_runs'],
        ]]
    );
})->purpose('Advance world clocks and process flights, crew, airports, maintenance, deliveries, leasing and payroll');

Artisan::command('airline:airport-backfill', function (AirportOperationsService $airportOperations): void {
    $summary = ['airlines' => 0, 'stations' => 0, 'slots' => 0, 'unavailable' => 0];

    Airline::query()
        ->with(['world', 'homeAirport', 'routes.origin', 'routes.destination'])
        ->where('status', 'active')
        ->orderBy('id')
        ->each(function (Airline $airline) use ($airportOperations, &$summary): void {
            $summary['airlines']++;
            $summary['stations'] += $airportOperations->ensureNetworkStations($airline);
            $result = $airportOperations->reserveMissingForAirline(
                $airline,
                $airline->world?->simulated_at ?? now()
            );
            $summary['slots'] += $result['reserved'];
            $summary['unavailable'] += $result['unavailable'];
        });

    $this->info('Airport operations backfill completed.');
    $this->table(
        ['Airlines', 'Stations created', 'Slots created', 'Unavailable flights'],
        [[$summary['airlines'], $summary['stations'], $summary['slots'], $summary['unavailable']]]
    );
})->purpose('Backfill stations and slots for existing airlines and future flights');


Artisan::command('airline:marketing-backfill', function (MarketingService $marketing): void {
    $summary = ['airlines' => 0, 'route_metrics_created' => 0];

    Airline::query()
        ->with('world')
        ->where('status', 'active')
        ->orderBy('id')
        ->each(function (Airline $airline) use ($marketing, &$summary): void {
            $summary['airlines']++;
            $result = $marketing->backfillAirline($airline);
            $summary['route_metrics_created'] += (int) ($result['route_metrics_created'] ?? 0);
        });

    $this->info('Marketing and reputation backfill completed.');
    $this->table(
        ['Airlines', 'Route metrics created'],
        [[$summary['airlines'], $summary['route_metrics_created']]]
    );
})->purpose('Backfill reputation profiles and route commercial metrics');


Artisan::command('airline:competition-backfill', function (MarketCompetitionService $competition): void {
    $summary = ['airlines' => 0, 'markets' => 0];

    Airline::query()
        ->with('world')
        ->where('status', 'active')
        ->orderBy('id')
        ->each(function (Airline $airline) use ($competition, &$summary): void {
            $summary['airlines']++;
            $result = $competition->backfillAirline(
                $airline,
                $airline->world?->simulated_at ?? now()
            );
            $summary['markets'] += (int) ($result['markets'] ?? 0);
        });

    $this->info('Competition and market share backfill completed.');
    $this->table(
        ['Airlines', 'Markets calculated'],
        [[$summary['airlines'], $summary['markets']]]
    );
})->purpose('Backfill current route competition scores and market shares');
