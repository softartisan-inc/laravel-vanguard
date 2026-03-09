<?php

// ============================================================
// EXAMPLE — app/Providers/AppServiceProvider.php
// ============================================================
// This file shows how to integrate Vanguard in your Laravel app.
// Copy the relevant parts into your existing AppServiceProvider.
// ============================================================

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SoftArtisan\Vanguard\Facades\Vanguard;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ── Option 1: Simple auth gate ─────────────────────────
        // Only allow admin users to access the dashboard.
        Vanguard::auth(function ($request) {
            return $request->user()?->role === 'admin';
        });

        // ── Option 2: Restrict to specific emails ──────────────
        // Vanguard::auth(function ($request) {
        //     return in_array($request->user()?->email, [
        //         'admin@yourapp.com',
        //         'devops@yourapp.com',
        //     ]);
        // });

        // ── Custom dashboard path ──────────────────────────────
        // Vanguard::path('admin/backups');
        // → accessible at: yourapp.com/admin/backups

        // ── Custom dashboard domain ────────────────────────────
        // Vanguard::domain('tools.yourapp.com');
        // → accessible at: tools.yourapp.com/vanguard

        // ── Disable routes (if you register your own) ─────────
        // Vanguard::ignoreRoutes();

        // ── Disable auto-migrations ────────────────────────────
        // Vanguard::ignoreMigrations();
    }
}
