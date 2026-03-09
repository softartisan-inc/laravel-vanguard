<?php

use Illuminate\Support\Facades\Route;
use SoftArtisan\Vanguard\Http\Controllers\DashboardController;
use SoftArtisan\Vanguard\Http\Controllers\BackupsApiController;
use SoftArtisan\Vanguard\Http\Controllers\SseController;
use SoftArtisan\Vanguard\Http\Middleware\VanguardAuthenticate;

Route::middleware([VanguardAuthenticate::class])->group(function () {

    // ─── JSON API — déclaré AVANT le catch-all SPA ────────────────
    Route::prefix('api')->name('vanguard.api.')->group(function () {
        Route::get('/stats',                 [BackupsApiController::class, 'stats'])->name('stats');
        Route::get('/backups',               [BackupsApiController::class, 'index'])->name('backups.index');
        Route::post('/backups/run',          [BackupsApiController::class, 'run'])->name('backups.run');
        Route::delete('/backups/{id}',       [BackupsApiController::class, 'destroy'])->name('backups.destroy');
        Route::post('/backups/{id}/restore', [BackupsApiController::class, 'restore'])->name('backups.restore');
        Route::get('/tenants',               [BackupsApiController::class, 'tenants'])->name('tenants.index');

        // SSE stream — requires its own route outside middleware that buffers output
        Route::get('/stream', [SseController::class, 'stream'])->name('stream');
    });

    // ─── Dashboard SPA — catch-all EN DERNIER ────────────────────
    Route::get('/',      [DashboardController::class, 'index'])->name('vanguard.dashboard');
    //Route::get('/{any}', [DashboardController::class, 'index'])->where('any', '.*');
});
