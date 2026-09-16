<?php

use App\Http\Controllers\AirlineController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

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
});
