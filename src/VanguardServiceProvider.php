<?php

namespace SoftArtisan\Vanguard;

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
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vanguard.php', 'vanguard');

        if (! class_exists('Vanguard')) {
            class_alias(Vanguard::class, 'Vanguard');
        }

        $this->app->singleton(DatabaseDriver::class);
        $this->app->singleton(StorageDriver::class);
        $this->app->singleton(BackupStorageManager::class);
        $this->app->singleton(TenancyResolver::class);

        $this->app->singleton(RestoreService::class, fn ($app) => new RestoreService(
            $app->make(DatabaseDriver::class),
            $app->make(StorageDriver::class),
            $app->make(BackupStorageManager::class),
        ));

        $this->app->singleton(BackupManager::class, fn ($app) => new BackupManager(
            $app->make(DatabaseDriver::class),
            $app->make(StorageDriver::class),
            $app->make(BackupStorageManager::class),
            $app->make(TenancyResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMigrations();
        $this->registerScheduler();
    }

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

    protected function registerRoutes(): void
    {
        if (Vanguard::$registersRoutes) {
            Route::group($this->routeConfiguration(), function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/vanguard.php');
            });
        }
    }

    protected function routeConfiguration(): array
    {
        return [
            'domain'     => config('vanguard.domain', null),
            'prefix'     => config('vanguard.path', 'vanguard'),
            'middleware' => config('vanguard.middleware', ['web']),
        ];
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vanguard');
    }

    protected function registerMigrations(): void
    {
        if (Vanguard::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function registerScheduler(): void
    {
        $this->app->booted(function () {
            app(VanguardScheduler::class)->schedule(
                $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
            );
        });
    }
}
