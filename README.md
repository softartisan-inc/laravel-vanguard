# Vanguard — Backup Manager for Laravel

A multi-tenant backup dashboard for Laravel, built with Vue 3 + Vite and real-time updates via Server-Sent Events.

---

## Installation

```bash
composer require softartisan/vanguard
```

### 1. Publish config & run migrations

```bash
php artisan vendor:publish --tag=vanguard-config
php artisan vendor:publish --tag=vanguard-migrations
php artisan migrate
```

### 2. Build frontend assets

```bash
cd vendor/softartisan/vanguard
npm install
npm run build
cd -
php artisan vendor:publish --tag=vanguard-assets
```

Local development with hot-reload:
```bash
cd vendor/softartisan/vanguard && npm run watch
```

On deploy: re-run `npm run build` + `vendor:publish --tag=vanguard-assets` only when the package version changes.

---

## Configuration — `config/vanguard.php`

```php
'path' => env('VANGUARD_PATH', 'vanguard'),   // yourapp.com/vanguard

'realtime' => [
    'driver'       => env('VANGUARD_REALTIME_DRIVER', 'sse'),  // 'sse' | 'polling'
    'interval'     => env('VANGUARD_POLL_INTERVAL', 5),        // seconds (polling only)
    'sse_interval' => env('VANGUARD_SSE_INTERVAL', 2),         // DB check interval (SSE)
    'max_lifetime' => env('VANGUARD_SSE_LIFETIME', 120),       // auto-reconnect after Ns
],
```

### Real-time drivers

| Driver | Mechanism | Best for |
|--------|-----------|----------|
| `sse` *(default)* | One persistent HTTP connection; server pushes only on state change | Most setups — zero overhead at idle |
| `polling` | API fetch every N seconds | Proxies/hosts that block streaming |

**Nginx**: add `proxy_buffering off;` to your location block for SSE.

---

## Authentication

```php
// AppServiceProvider::boot()
use SoftArtisan\Vanguard\Facades\Vanguard;

Vanguard::auth(fn (Request $r) => $r->user()?->isAdmin());
```

---

## Multi-tenancy

```php
'tenancy' => [
    'enabled'      => true,
    'tenant_model' => \App\Models\Tenant::class,
    'tenant_key'   => 'id',
],
```

---

## Frontend architecture

```
resources/
├── css/vanguard.css
└── js/vanguard/
    ├── app.js                  ← Vue entry point
    ├── App.vue                 ← layout, navigation, realtime orchestration
    ├── composables/
    │   ├── useApi.js           ← fetch wrapper (CSRF, base URL via inject)
    │   ├── useBackups.js       ← shared state: stats, backups, tenants
    │   ├── useRealtime.js      ← SSE / polling driver (auto-fallback)
    │   └── useToast.js         ← global toast notifications
    ├── components/
    │   ├── BackupTable.vue     ← reusable table (with or without actions)
    │   ├── StatCards.vue
    │   ├── RunModal.vue
    │   ├── VBadge.vue          ← status badge (completed/running/failed/pending)
    │   ├── VPagination.vue
    │   ├── VToast.vue
    │   └── RealtimeIndicator.vue  ← Live / Polling / Offline dot in sidebar
    └── pages/
        ├── Dashboard.vue
        ├── Backups.vue         ← full list with status/type filters + pagination
        └── Tenants.vue
```

The Blade layout is a minimal shell — mounts Vue and passes config via `data-*` attributes. No inline JS, no global variables.

---

## Extending Vanguard — IoC bindings

All core services are registered through the Laravel container and can be swapped with custom implementations in your `AppServiceProvider` (or any service provider that boots after `VanguardServiceProvider`).

### Container overview

| Class | Registration | Notes |
|-------|-------------|-------|
| `DatabaseDriver` | `singleton` | Stateless — safe to share |
| `StorageDriver` | `singleton` | Stateless — safe to share |
| `TenancyResolver` | `singleton` | Stateless — safe to share |
| `BackupStorageManager` | `bind` (transient) | Holds session-scoped tmp path |
| `BackupManager` | `bind` (transient) | Gets a fresh `BackupStorageManager` per job |
| `RestoreService` | `bind` (transient) | Gets a fresh `BackupStorageManager` per job |

> **Why transient for BackupManager?** Long-running queue workers reuse the same process across many jobs. A singleton `BackupManager` would leak the tmp directory path from job N into job N+1. Always use `bind()` when overriding these classes.

### Swap the BackupManager

```php
// app/Providers/AppServiceProvider.php
use App\Backup\CustomBackupManager;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

public function register(): void
{
    $this->app->bind(BackupManager::class, fn ($app) => new CustomBackupManager(
        $app->make(DatabaseDriver::class),
        $app->make(StorageDriver::class),
        $app->make(BackupStorageManager::class),
        $app->make(TenancyResolver::class),
    ));
}
```

Your `CustomBackupManager` extends `BackupManager` and overrides only what you need:

```php
namespace App\Backup;

use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;

class CustomBackupManager extends BackupManager
{
    public function backupTenant(mixed $tenant, array $options = []): BackupRecord
    {
        // Custom pre-backup hook
        \Log::info('Starting custom backup for tenant', ['id' => $tenant->getTenantKey()]);

        return parent::backupTenant($tenant, $options);
    }
}
```

### Swap the DatabaseDriver

Useful to add support for a custom dump tool or encryption layer:

```php
use App\Backup\EncryptedDatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;

$this->app->singleton(DatabaseDriver::class, EncryptedDatabaseDriver::class);
```

### Swap the TenancyResolver

Override tenant resolution when you don't use `stancl/tenancy` or when your tenant model has a non-standard structure:

```php
use App\Backup\CustomTenancyResolver;
use SoftArtisan\Vanguard\Services\TenancyResolver;

$this->app->singleton(TenancyResolver::class, CustomTenancyResolver::class);
```

### Swap the VanguardScheduler

Replace the scheduler entirely to take full control of when backups run:

```php
use App\Backup\CustomVanguardScheduler;
use SoftArtisan\Vanguard\Console\VanguardScheduler;

$this->app->singleton(VanguardScheduler::class, CustomVanguardScheduler::class);
```

---

## Per-tenant schedule customization

### Via the `vanguard_schedule` column (recommended)

Each tenant can carry its own cron expression. Add the column via a migration:

```php
Schema::table('tenants', function (Blueprint $table) {
    $table->string('vanguard_schedule')->nullable();
});
```

Then set it per tenant:

```php
$tenant->update(['vanguard_schedule' => '0 3 * * 1']); // Every Monday at 03:00
```

`VanguardScheduler` reads `$tenant->vanguard_schedule` automatically — no extra code needed. Tenants without the column (or with `null`) fall back to the global schedule defined in `config/vanguard.php`.

### Via a custom TenancyResolver

For more complex logic (e.g. schedule stored in Redis, driven by a feature flag, or computed from the tenant's timezone):

```php
namespace App\Backup;

use SoftArtisan\Vanguard\Services\TenancyResolver;

class CustomTenancyResolver extends TenancyResolver
{
    public function tenantSchedule(mixed $tenant): ?string
    {
        // Example: honour the tenant's local timezone
        $tz   = $tenant->timezone ?? 'UTC';
        $hour = (new \DateTime('02:00', new \DateTimeZone($tz)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('G');

        return "0 {$hour} * * *";
    }
}
```

Register it as a singleton before `VanguardServiceProvider` boots (or in a provider with a higher priority):

```php
$this->app->singleton(TenancyResolver::class, CustomTenancyResolver::class);
```

---

## Multiple landlord schedules

The default scheduler registers one cron entry for the landlord backup. To run multiple backup types at different times (e.g. database nightly, filesystem weekly), swap the `VanguardScheduler` with a custom subclass:

```php
namespace App\Backup;

use Illuminate\Console\Scheduling\Schedule;
use SoftArtisan\Vanguard\Console\VanguardScheduler;

class MultiScheduleVanguardScheduler extends VanguardScheduler
{
    public function schedule(Schedule $schedule): void
    {
        if (! config('vanguard.schedule.enabled', true)) {
            return;
        }

        $tz = config('vanguard.schedule.timezone', config('app.timezone', 'UTC'));

        // ── Database-only landlord backup — every night at 02:00 ──────────────
        $this->scheduleCommand(
            $schedule,
            'vanguard:backup --landlord --no-filesystem',
            '0 2 * * *',
            $tz,
        );

        // ── Full landlord backup (DB + filesystem) — Sundays at 03:00 ────────
        $this->scheduleCommand(
            $schedule,
            'vanguard:backup --landlord',
            '0 3 * * 0',
            $tz,
        );

        // ── Per-tenant backups — keep the default per-tenant logic ────────────
        if (config('vanguard.schedule.tenants', true) && $this->tenancy->isEnabled()) {
            foreach ($this->tenancy->allTenants() as $tenant) {
                $cron = $this->tenancy->tenantSchedule($tenant) ?? $this->globalCron();
                $this->scheduleCommand(
                    $schedule,
                    "vanguard:backup --tenant={$tenant->getTenantKey()}",
                    $cron,
                    $tz,
                );
            }
        }

        // ── Pruning and tmp cleanup — inherited defaults ───────────────────────
        if (config('vanguard.retention.enabled', true)) {
            $schedule->command('vanguard:prune')
                ->daily()->timezone($tz)->withoutOverlapping()->runInBackground();
        }

        $schedule->command('vanguard:cleanup-tmp')
            ->hourly()->timezone($tz)->withoutOverlapping()->runInBackground();
    }
}
```

Register it in your service provider **before** `VanguardServiceProvider` (or override in `AppServiceProvider::register()`):

```php
use App\Backup\MultiScheduleVanguardScheduler;
use SoftArtisan\Vanguard\Console\VanguardScheduler;

$this->app->singleton(VanguardScheduler::class, MultiScheduleVanguardScheduler::class);
```

> `scheduleCommand()` and `globalCron()` are `protected` methods — they are part of the extension API and will not change between patch releases.
