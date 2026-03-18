<?php

namespace SoftArtisan\Vanguard;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SoftArtisan\Vanguard\Commands\VanguardBackupCommand;
use SoftArtisan\Vanguard\Commands\VanguardRestoreCommand;
use SoftArtisan\Vanguard\Commands\VanguardListCommand;
use SoftArtisan\Vanguard\Commands\VanguardPruneCommand;
use SoftArtisan\Vanguard\Commands\VanguardInstallCommand;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Console\VanguardScheduler;

class VanguardServiceProvider extends ServiceProvider
{
    /**
     * Register package services and singletons into the container.
     *
     * Merges the package config so host-app overrides take precedence,
     * registers a class alias for the Vanguard facade root, and binds all
     * service singletons.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vanguard.php', 'vanguard');

        if (! class_exists('Vanguard')) {
            class_alias(Vanguard::class, 'Vanguard');
        }

        // Stateless helpers — safe as singletons.
        $this->app->singleton(DatabaseDriver::class);
        $this->app->singleton(StorageDriver::class);
        $this->app->singleton(TenancyResolver::class);

        // BackupStorageManager holds session-scoped state ($sessionTmpDir).
        // BackupManager and RestoreService own a BackupStorageManager instance.
        // All three must be transient (bind) so that each queue job / request
        // gets a clean instance — singletons would leak stale tmp paths across
        // jobs in long-running workers.
        $this->app->bind(BackupStorageManager::class);

        $this->app->bind(RestoreService::class, fn ($app) => new RestoreService(
            $app->make(DatabaseDriver::class),
            $app->make(StorageDriver::class),
            $app->make(BackupStorageManager::class),
        ));

        $this->app->bind(BackupManager::class, fn ($app) => new BackupManager(
            $app->make(DatabaseDriver::class),
            $app->make(StorageDriver::class),
            $app->make(BackupStorageManager::class),
            $app->make(TenancyResolver::class),
        ));
    }

    /**
     * Bootstrap package services.
     *
     * Registers publishable assets, Artisan commands, routes, views,
     * migrations, and the scheduler in the correct order.
     */
    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerRateLimiters();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMigrations();
        $this->registerScheduler();
    }

    /**
     * Register publishable stubs for vendor:publish.
     *
     * Available tags: vanguard-config, vanguard-migrations, vanguard-views, vanguard-assets.
     * Only runs in console context to avoid overhead on HTTP requests.
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/vanguard.php' => config_path('vanguard.php'),
        ], 'vanguard-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'vanguard-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/vanguard'),
        ], 'vanguard-views');

        // php artisan vendor:publish --tag=vanguard-assets
        // Publie les fichiers JS/CSS compilés dans public/vendor/vanguard/
        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/vanguard'),
        ], 'vanguard-assets');
    }

    /**
     * Register named rate limiters used by the Vanguard API routes.
     *
     * Each limiter is keyed by authenticated user ID when available,
     * falling back to IP address for unauthenticated contexts.
     * Set the corresponding env variable to 0 to disable a limiter.
     */
    protected function registerRateLimiters(): void
    {
        $by = fn (Request $r) => $r->user()?->id ?: $r->ip();

        foreach ([
            'vanguard.run'     => 'run',
            'vanguard.restore' => 'restore',
            'vanguard.api'     => 'api',
        ] as $name => $key) {
            $max = (int) config("vanguard.rate_limits.{$key}", 60);

            RateLimiter::for($name, $max > 0
                ? fn (Request $r) => Limit::perMinute($max)->by($by($r))
                : fn ()           => Limit::none(),
            );
        }
    }

    /**
     * Register Vanguard's Artisan commands.
     *
     * Only runs in console context to avoid loading command classes on HTTP requests.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            VanguardInstallCommand::class,
            VanguardBackupCommand::class,
            VanguardRestoreCommand::class,
            VanguardListCommand::class,
            VanguardPruneCommand::class,
        ]);
    }

    /**
     * Register the Vanguard dashboard and API routes.
     *
     * Skipped when Vanguard::ignoreRoutes() has been called (e.g. for manual route registration).
     */
    protected function registerRoutes(): void
    {
        if (Vanguard::$registersRoutes) {
            Route::group($this->routeConfiguration(), function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/vanguard.php');
            });
        }
    }

    /**
     * Build the route group configuration array from the package config.
     *
     * @return array{domain: string|null, prefix: string, middleware: array<string>}
     */
    protected function routeConfiguration(): array
    {
        return [
            'domain'     => config('vanguard.domain', null),
            'prefix'     => config('vanguard.path', 'vanguard'),
            'middleware' => config('vanguard.middleware', ['web']),
        ];
    }

    /**
     * Register the Vanguard Blade view namespace ('vanguard::').
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vanguard');
    }

    /**
     * Load Vanguard's migrations automatically.
     *
     * Skipped when Vanguard::ignoreMigrations() has been called so that
     * applications managing their own migration files are not affected.
     */
    protected function registerMigrations(): void
    {
        if (Vanguard::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register the VanguardScheduler after the application has fully booted.
     *
     * Deferring until 'booted' ensures the Schedule singleton is resolved
     * after all other service providers have had a chance to configure it.
     */
    protected function registerScheduler(): void
    {
        $this->app->booted(function () {
            app(VanguardScheduler::class)->schedule(
                $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
            );
        });
    }
}
