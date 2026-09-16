<?php

use App\Http\Controllers\AirlineController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FlightScheduleController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\SimulationController;
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
    Route::post('/operations/fleet/purchase', [OperationsController::class, 'purchaseAircraft'])->name('operations.fleet.purchase');
    Route::post('/operations/routes', [OperationsController::class, 'storeRoute'])->name('operations.routes.store');
    Route::patch('/operations/routes/{route}/fares', [OperationsController::class, 'updateRouteFares'])->name('operations.routes.fares.update');
    Route::post('/operations/flights', [OperationsController::class, 'scheduleFlight'])->name('operations.flights.store');

    Route::get('/schedules', [FlightScheduleController::class, 'index'])->name('schedules.index');
    Route::post('/schedules', [FlightScheduleController::class, 'store'])->name('schedules.store');
    Route::patch('/schedules/{schedule}/toggle', [FlightScheduleController::class, 'toggle'])->name('schedules.toggle');
});
