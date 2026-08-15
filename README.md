# Vanguard — Backup Manager for Laravel

A multi-tenant backup dashboard for Laravel, built with Vue 3 + Vite and real-time updates via Server-Sent Events.

---

## Requirements

| | Supported |
|---|---|
| PHP | `^8.3` (CI runs 8.3, 8.4, 8.5) |
| Laravel | `^12.0` only |
| System binaries | `tar`, `gzip` (GNU tar); `mysqldump`/`mysql` or `pg_dump`/`psql` for database backups |

PHP 8.1/8.2 and Laravel 10/11 were dropped in 2.0 — see [Upgrading from 1.x to 2.0](#upgrading-from-1x-to-20).

Without the `mysqldump` binary, MySQL dumps fall back to a pure-PDO implementation (slower, but no binary needed). Binary locations can be pinned in `vanguard.binaries` when they are not in the PATH of your web/queue process.

---

## Upgrading from 1.x to 2.0

Four things to check before upgrading.

**1. PHP and Laravel.** 2.0 requires PHP `^8.3` and Laravel `^12.0`. Laravel 11 was dropped deliberately: every 11.x release currently carries open security advisories, and Composer refuses to resolve it without waiving them. If you are on Laravel 10 or 11, upgrade the framework first — stay on Vanguard 1.x until then.

**2. A backup that always "succeeded" may now start failing — loudly, and correctly.** Up to 1.x the MySQL dump ran as a shell pipeline (`mysqldump … 2>&1 | gzip > file`), so the exit code Vanguard checked was gzip's, not mysqldump's. A dump that failed halfway — missing privilege, dead connection, full disk — was still recorded as a completed backup, and mysqldump's error message was written *inside* the `.sql.gz`. 2.0 runs mysqldump directly (no shell), keeps stderr on a separate pipe, checks mysqldump's own exit code, and verifies every byte reaches the disk; a failed or unwritable dump now throws and the partial file is deleted. If a nightly backup starts failing right after the upgrade, it was very probably already broken — read the error, do not silence it. Restoring one of your existing `.sql.gz` files into a scratch database is the fastest way to find out how long it has been broken.

**3. The filesystem archive layout changed (and filesystem restore now actually works).** 1.x archives were created from absolute paths, so tar stored members like `var/www/html/storage/app/…`; extracting them into `storage/` recreated that whole tree *under* `storage/` instead of putting the files back. Archives are now created relative to `storage_path()`, so `storage/app/public/x.png` is stored as `./app/public/x.png` and lands back exactly where it came from. **Archives taken with 1.x remain restorable:** the restore lists the archive's members and works the legacy prefix out of their shape — members announced as `./…` mean a current archive and nothing is stripped; otherwise the leading chain common to every member is cut at the component named like the destination (`storage`), falling back to the depth at which the configured backup roots appear. The prefix is then dropped with `--strip-components`. It is never read from the producing machine's path, so a bundle made in production restores on staging just as well, and old and new archives can live side by side.

**4. New config key.** `vanguard.dump.mysql_options` (see [MySQL dump options](#mysql-dump-options)). Publishing the config again is optional — a published 1.x config with no `dump` section falls back to the same defaults in code.

```bash
composer require softartisan/laravel-vanguard:^2.0
php artisan vendor:publish --tag=vanguard-config --force   # optional, to pick up the new section
```

---

## Installation

```bash
composer require softartisan/laravel-vanguard
```

### 1. Publish config & run migrations

```bash
php artisan vendor:publish --tag=vanguard-config
php artisan vendor:publish --tag=vanguard-migrations
php artisan migrate
```

### 2. Build frontend assets

```bash
cd vendor/softartisan/laravel-vanguard
npm install
npm run build
cd -
php artisan vendor:publish --tag=vanguard-assets
```

Local development with hot-reload:
```bash
cd vendor/softartisan/laravel-vanguard && npm run watch
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

### MySQL dump options

Options handed to `mysqldump` on every MySQL/MariaDB dump:

```php
'dump' => [
    'mysql_options' => env(
        'VANGUARD_MYSQL_DUMP_OPTIONS',
        '--single-transaction --quick --routines --triggers --no-tablespaces',
    ),
],
```

A space-separated string **or** an array of options — both are accepted. The value replaces the defaults, it is not merged with them, so repeat the ones you want to keep.

| Default option | Why |
|---|---|
| `--single-transaction` | Consistent snapshot **without locking the tables** — the application keeps writing during the dump (InnoDB) |
| `--quick` | mysqldump streams rows instead of buffering a whole table in memory |
| `--routines` `--triggers` | Stored procedures, functions and triggers are now part of the dump — restoring a backup restores them too |
| `--no-tablespaces` | Avoids the `PROCESS` privilege MySQL 8 would otherwise demand |

`--events` is deliberately **not** a default: it requires the `EVENT` privilege, which managed/restricted accounts often lack, and its absence would make the whole dump fail. Add it if your account has it:

```dotenv
VANGUARD_MYSQL_DUMP_OPTIONS="--single-transaction --quick --routines --triggers --events --no-tablespaces"
```

These options apply to the `mysqldump` binary only. When the binary is missing, the PDO fallback takes over: it reads every table with buffering off and writes batched multi-row `INSERT`s, so a large table is never loaded into PHP memory.

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

## Restoring a backup

```bash
php artisan vanguard:restore {id}
                  [--source=local|remote|ftp]
                  [--no-verify] [--no-db]
                  [--restore-storage] [--wipe-storage]
                  [--force]
```

| Option | Effect |
|---|---|
| `--source=` | Which destination the bundle is read back from. Omit it and Vanguard uses **the first destination that actually holds a path** (local, then remote, then ftp) — a backup that only ever reached S3 restores without any flag. Pass it explicitly to force one, e.g. to restore the remote copy while a local one exists. Asking for a destination the backup never reached is an error naming the ones available. |
| `--no-verify` | Skip the SHA-256 checksum check |
| `--no-db` | Restore the filesystem only |
| `--restore-storage` | Also restore the filesystem (opt-in; the database alone is restored by default) |
| `--wipe-storage` | Replace instead of merge — see below. Requires `--restore-storage` |
| `--force` | Skip every confirmation prompt |

### Merge (default) vs. replace (`--wipe-storage`)

A filesystem restore **merges** by default: the archive is extracted over `storage/`, so files created after the backup was taken survive it. That is the safe behaviour, and it is what you want most of the time.

`--wipe-storage` gives you the point-in-time state instead. Before extracting, it empties the directories listed in `vanguard.sources.filesystem_paths` — **only those**, resolved under `storage_path()`:

- `storage/` itself is never wiped. Logs, `framework/` caches and sessions live there and are not in the backup, so they must survive the restore.
- Each directory node is kept and only its content removed, so permissions and any symlink pointing at it stay intact.
- A configured path that resolves outside `storage_path()` (a stray `''`, `.` or `../..`) is refused and logged rather than followed.

It only makes sense together with a filesystem restore, and it is not made to imply it: `--wipe-storage` on its own exits with an error. Interactively it asks a second, separate confirmation listing the exact directories about to be erased; `--force` skips both prompts.

```bash
# Merge: restore files, keep anything added since the backup
php artisan vanguard:restore 42 --restore-storage

# Replace: storage/app becomes exactly what the backup holds
php artisan vanguard:restore 42 --restore-storage --wipe-storage
```

> `--wipe-storage` is CLI-only on purpose. The dashboard API (`POST /api/backups/{id}/restore`) accepts `verify_checksum`, `restore_db`, `restore_storage` and `source`, but never wipes.

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
