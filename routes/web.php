<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::get('/health', HealthController::class)->name('health');
