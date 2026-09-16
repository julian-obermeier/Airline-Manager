<?php

use App\Services\Simulation\FlightSimulationService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('airline:status', function (): void {
    $this->info('Airline Empire foundation is available.');
})->purpose('Show the current application bootstrap status');

Artisan::command('airline:simulate', function (FlightSimulationService $simulation): void {
    $summary = $simulation->tick();

    $this->info('Simulation tick completed.');
    $this->table(
        ['Worlds', 'Checked', 'Boarding', 'Departed', 'In air', 'Completed'],
        [[
            $summary['worlds'],
            $summary['flights_checked'],
            $summary['boarding'],
            $summary['departed'],
            $summary['in_air'],
            $summary['completed'],
        ]]
    );
})->purpose('Advance world clocks and process due flights');
