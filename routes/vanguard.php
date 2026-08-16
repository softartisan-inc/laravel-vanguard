<?php

use Illuminate\Support\Facades\Route;
use SoftArtisan\Vanguard\Http\Controllers\AssetsController;
use SoftArtisan\Vanguard\Http\Controllers\BackupsApiController;
use SoftArtisan\Vanguard\Http\Controllers\DashboardController;
use SoftArtisan\Vanguard\Http\Controllers\MaintenanceApiController;
use SoftArtisan\Vanguard\Http\Controllers\RestoresApiController;
use SoftArtisan\Vanguard\Http\Controllers\SseController;
use SoftArtisan\Vanguard\Http\Middleware\VanguardAuthenticate;

// ─── Assets — public, no auth required ───────────────────────────────────────
Route::get('/assets/{file}', [AssetsController::class, 'serve'])
    ->name('vanguard.assets')
    ->where('file', 'vanguard\.(js|css)');

Route::middleware([VanguardAuthenticate::class])->group(function () {

    // ─── JSON API — déclaré AVANT le catch-all SPA ────────────────
    Route::prefix('api')->name('vanguard.api.')->group(function () {

        // Read-only endpoints — general API rate limit
        Route::middleware('throttle:vanguard.api')->group(function () {
            Route::get('/stats', [BackupsApiController::class, 'stats'])->name('stats');
            Route::get('/backups', [BackupsApiController::class, 'index'])->name('backups.index');
            Route::get('/tenants', [BackupsApiController::class, 'tenants'])->name('tenants.index');
            Route::delete('/backups/{id}', [BackupsApiController::class, 'destroy'])->name('backups.destroy');
            Route::get('/restores', [RestoresApiController::class, 'index'])->name('restores.index');
            Route::get('/restores/{id}', [RestoresApiController::class, 'show'])->name('restores.show')
                ->where('id', '[0-9]+');
            Route::get('/stream', [SseController::class, 'stream'])->name('stream');
        });

        // Backup trigger — tightly rate-limited (heavy server operation)
        Route::post('/backups/run', [BackupsApiController::class, 'run'])
            ->middleware('throttle:vanguard.run')
            ->name('backups.run');

        // Restore — most restrictive (destructive, irreversible)
        Route::post('/backups/{id}/restore', [BackupsApiController::class, 'restore'])
            ->middleware('throttle:vanguard.restore')
            ->name('backups.restore');

        // Maintenance — prune is as irreversible as a restore, so it shares the
        // strictest limiter. The tmp sweep only touches scratch directories and
        // sits with the other heavy-but-recoverable operations.
        Route::post('/prune', [MaintenanceApiController::class, 'prune'])
            ->middleware('throttle:vanguard.restore')
            ->name('prune');

        Route::post('/cleanup-tmp', [MaintenanceApiController::class, 'cleanupTmp'])
            ->middleware('throttle:vanguard.run')
            ->name('cleanup-tmp');
    });

    // ─── Dashboard SPA — catch-all EN DERNIER ────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('vanguard.dashboard');
    // Route::get('/{any}', [DashboardController::class, 'index'])->where('any', '.*');
});
