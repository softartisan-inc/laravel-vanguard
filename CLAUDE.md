# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

**Vanguard** is a Laravel package (`softartisan/vanguard`) — a multi-tenant backup dashboard with a Vue 3 SPA frontend and real-time updates via Server-Sent Events. It is published to Packagist and installed via `composer require`.

## Commands

### PHP / Testing
```bash
composer test                        # Run all tests
composer test:unit                   # Unit tests only
composer test:feature                # Feature tests only
vendor/bin/phpunit --filter TestName # Run a single test
composer lint                        # Laravel Pint (auto-fixes code style)
```

### Frontend
```bash
npm install
npm run build    # Production build → public/
npm run dev      # Vite dev server (hot-reload)
npm run watch    # Watch mode build
```

Tests use SQLite in-memory. Tenancy, queues, and scheduling are disabled by default in the test environment (see `phpunit.xml` and `tests/TestCase.php`).

## Architecture

### PHP Package Structure (`src/`)

| Path | Role |
|------|------|
| `Vanguard.php` | Static facade-like class: holds `$authUsing`, `$registersRoutes`, `$runsMigrations` flags, and config helpers |
| `VanguardServiceProvider.php` | Registers all singletons, routes, views, migrations, commands, and the scheduler |
| `Services/BackupManager.php` | Core service: `backupLandlord()`, `backupTenant()`, `backupFilesystem()`, `backupAllTenants()` — fires `BackupStarted/Completed/Failed` events |
| `Services/BackupStorageManager.php` | Handles tmp dir, bundling files, local/remote disk storage |
| `Services/Drivers/DatabaseDriver.php` | Dumps databases (mysql/pgsql/sqlite) to `.sql.gz` |
| `Services/Drivers/StorageDriver.php` | Archives filesystem paths to `.tar.gz` |
| `Services/TenancyResolver.php` | Abstracts stancl/tenancy v3 — `allTenants()`, `runForTenant()`, `landlordDbConfig()`, `tenantDbConfig()` |
| `Services/RestoreService.php` | Restores from a backup archive |
| `Http/Controllers/BackupsApiController.php` | JSON API: stats, list, run, restore, delete, tenants |
| `Http/Controllers/SseController.php` | Streams real-time backup state changes via SSE |
| `Http/Controllers/DashboardController.php` | Renders the Blade shell that mounts Vue |
| `Http/Middleware/VanguardAuthenticate.php` | Calls `Vanguard::check($request)` — delegates to the `$authUsing` callback |
| `Console/VanguardScheduler.php` | Reads `vanguard.schedule` config and registers Artisan commands with Laravel's scheduler |
| `Models/BackupRecord.php` | Eloquent model: tracks status (`running/completed/failed`), paths, size, checksum, sources, destinations |

### Routes (all prefixed with `vanguard.path`, default `/vanguard`)
- `GET  /api/stats` — dashboard statistics
- `GET  /api/backups` — paginated backup list (filterable by status/type)
- `POST /api/backups/run` — trigger a backup
- `DELETE /api/backups/{id}` — delete a backup record
- `POST /api/backups/{id}/restore` — restore from a backup
- `GET  /api/tenants` — list tenants (when tenancy enabled)
- `GET  /api/stream` — SSE endpoint for real-time updates
- `GET  /` — Vue SPA shell

### Vue Frontend (`resources/js/vanguard/`)

The Blade view is a minimal shell that mounts Vue and passes config via `data-*` attributes — no global JS variables. Key composables:

- `useApi.js` — fetch wrapper with CSRF handling and base URL injection
- `useBackups.js` — shared reactive state: stats, backups list, tenant list
- `useRealtime.js` — SSE/polling driver with automatic fallback
- `useToast.js` — global toast notifications

### Key Conventions

- **Backup flow**: `BackupManager` → creates a `BackupRecord` with `status=running` → calls `DatabaseDriver`/`StorageDriver` → bundles files via `BackupStorageManager` → updates record to `completed/failed` → fires events
- **Queue support**: When `vanguard.queue.enabled=true`, `backupAllTenants()` dispatches `RunTenantBackupJob` instead of running synchronously
- **Multi-tenancy**: The `TenancyResolver` wraps stancl/tenancy and is a no-op when tenancy is disabled — all code paths work with or without it
- **Auth gate**: Set `Vanguard::auth(fn($r) => ...)` in `AppServiceProvider::boot()`. Defaults to `$request->user() !== null`
- **Publishing tags**: `vanguard-config`, `vanguard-migrations`, `vanguard-views`, `vanguard-assets`

### Artisan Commands
- `vanguard:install` — publishes config and migrations
- `vanguard:backup` — run a backup manually
- `vanguard:restore` — restore from a backup
- `vanguard:list` — list backup records
- `vanguard:prune` — delete backups past retention period
