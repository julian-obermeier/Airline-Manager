<?php

use App\Http\Controllers\AirlineController;
use App\Http\Controllers\AirportOperationsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CrewController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\FleetMarketController;
use App\Http\Controllers\FlightScheduleController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\RevenueManagementController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\WorldMapController;
use Illuminate\Support\Facades\Route;

Route::get('/system/cron/simulate', SimulationController::class)
    ->middleware('throttle:12,1')
    ->name('simulation.tick');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [GameController::class, 'home'])->name('home');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/worlds', [GameController::class, 'worlds'])->name('worlds.index');
    Route::post('/worlds/{world}/enter', [GameController::class, 'enterWorld'])->name('worlds.enter');

    Route::get('/airline/create', [AirlineController::class, 'create'])->name('airlines.create');
    Route::post('/airline', [AirlineController::class, 'store'])->name('airlines.store');

    Route::get('/dashboard', [GameController::class, 'dashboard'])->name('dashboard');

    Route::get('/operations', [OperationsController::class, 'index'])->name('operations.index');
    Route::post('/operations/routes', [OperationsController::class, 'storeRoute'])->name('operations.routes.store');
    Route::post('/operations/flights', [OperationsController::class, 'scheduleFlight'])
        ->middleware('aircraft.position')
        ->name('operations.flights.store');

    Route::get('/fleet', [FleetController::class, 'index'])->name('fleet.index');
    Route::post('/fleet/{aircraft}/sell', [FleetController::class, 'sell'])->name('fleet.sell');

    Route::get('/fleet-market', [FleetMarketController::class, 'index'])->name('fleet-market.index');
    Route::post('/fleet-market/new', [FleetMarketController::class, 'orderNew'])->name('fleet-market.new');
    Route::post('/fleet-market/lease', [FleetMarketController::class, 'orderLease'])->name('fleet-market.lease');
    Route::post('/fleet-market/used/{offer}', [FleetMarketController::class, 'buyUsed'])->name('fleet-market.used');

    Route::get('/schedules', [FlightScheduleController::class, 'index'])->name('schedules.index');
    Route::post('/schedules', [FlightScheduleController::class, 'store'])->name('schedules.store');
    Route::patch('/schedules/{schedule}/toggle', [FlightScheduleController::class, 'toggle'])->name('schedules.toggle');

    Route::get('/airport-operations', [AirportOperationsController::class, 'index'])->name('airport-operations.index');
    Route::post('/airport-operations/stations', [AirportOperationsController::class, 'storeStation'])->name('airport-operations.stations.store');

    Route::get('/crew', [CrewController::class, 'index'])->name('crew.index');
    Route::post('/crew', [CrewController::class, 'store'])->name('crew.store');
    Route::post('/crew/{crewMember}/qualifications', [CrewController::class, 'storeQualification'])->name('crew.qualifications.store');
    Route::patch('/crew/{crewMember}/terminate', [CrewController::class, 'terminate'])->name('crew.terminate');

    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::patch('/maintenance/{event}/cancel', [MaintenanceController::class, 'cancel'])->name('maintenance.cancel');

    Route::get('/revenue-management', [RevenueManagementController::class, 'index'])->name('revenue-management.index');
    Route::patch('/revenue-management/routes/{route}/fares', [RevenueManagementController::class, 'updateBaseFares'])->name('revenue-management.fares.update');
    Route::patch('/revenue-management/routes/{route}/policy', [RevenueManagementController::class, 'updatePolicy'])->name('revenue-management.policy.update');
    Route::post('/revenue-management/flights/{flight}/reprice', [RevenueManagementController::class, 'reprice'])->name('revenue-management.flights.reprice');

    Route::get('/market', [MarketController::class, 'index'])->name('market.index');

    Route::get('/marketing', [MarketingController::class, 'index'])->name('marketing.index');
    Route::post('/marketing', [MarketingController::class, 'store'])->name('marketing.store');
    Route::patch('/marketing/{campaign}/cancel', [MarketingController::class, 'cancel'])->name('marketing.cancel');

    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/map', [WorldMapController::class, 'index'])->name('map.index');
});
