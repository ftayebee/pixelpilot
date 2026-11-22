<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationHistoryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : Inertia::render('Welcome');
})->name('home');

Route::get('/health', HealthController::class)->name('health');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('/generate/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:120,1')
        ->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('/generations', [GenerationHistoryController::class, 'index'])->name('generations.index');
    Route::get('/generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::get('/generations/{generation}/image', [GenerationHistoryController::class, 'image'])->name('generations.image');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
        Route::post('/analytics/sync', [AnalyticsController::class, 'sync'])->middleware('throttle:6,1')->name('analytics.sync');
    });

    Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('/credits/purchase', [CreditController::class, 'purchase'])->middleware('throttle:10,1')->name('credits.purchase');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');
});

require __DIR__.'/auth.php';
