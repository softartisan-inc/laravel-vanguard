# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

**Vanguard** is a Laravel package (`softartisan/laravel-vanguard`) — a multi-tenant backup dashboard with a Vue 3 SPA frontend and real-time updates via Server-Sent Events. It is published to Packagist and installed via `composer require`.

Supported since 2.0: PHP `^8.3`, Laravel `^12.0` only. CI matrix: PHP 8.3/8.4/8.5 × Laravel 12. PHP 8.1/8.2 and Laravel 10/11 were dropped (11.x carries open security advisories Composer refuses to resolve around). `composer.lock` is gitignored, so CI installs with `composer update`, not `install`.

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

Tests use SQLite in-memory. Tenancy, queues, and scheduling are disabled by default in the test environment (see `phpunit.xml.dist` and `tests/TestCase.php`).

- `phpunit.xml.dist` is **versioned** — it is what makes `composer test` work on a fresh clone. `phpunit.xml` is gitignored (anchored `/phpunit.xml`) and is a local override only. The `Unit` / `Feature` suite names are contractual: `composer test:unit` and `test:feature` select them by name.
- Tests are declared with the `#[Test]` attribute, never `/** @test */` — PHPUnit 12 removed doc-comment metadata and would collect zero tests. `phpunit/phpunit` is constrained to `^10.5|^11.0|^12.0`, `orchestra/testbench` to `^10.0`.

## Architecture

### PHP Package Structure (`src/`)

| Path | Role |
|------|------|
| `Vanguard.php` | Static facade-like class: holds `$authUsing`, `$registersRoutes`, `$runsMigrations` flags, and config helpers |
| `VanguardServiceProvider.php` | Registers all singletons, routes, views, migrations, commands, and the scheduler |
| `Services/BackupManager.php` | Core service: `backupLandlord()`, `backupTenant()`, `backupFilesystem()`, `backupAllTenants()` — fires `BackupStarted/Completed/Failed` events |
| `Services/BackupStorageManager.php` | Handles tmp dir, bundling files, local/remote disk storage |
| `Services/Drivers/DatabaseDriver.php` | Dumps databases (mysql/pgsql/sqlite) to `.sql.gz`; MySQL via `mysqldump` (`proc_open`, no shell) with a pure-PDO fallback |
| `Services/Drivers/StorageDriver.php` | Archives filesystem paths to `.tar.gz`, members stored relative to `storage_path()` |
| `Services/TenancyResolver.php` | Abstracts stancl/tenancy v3 — `allTenants()`, `runForTenant()`, `landlordDbConfig()`, `tenantDbConfig()` |
| `Services/RestoreService.php` | Restores from a backup archive; resolves the source destination and owns the `--wipe-storage` scope (`backedUpPaths()` is public) |
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
- **Dump integrity** (`DatabaseDriver`): the MySQL CLI dump runs through `proc_open` with an argv array — no shell, so the checked exit code is mysqldump's own (it used to be gzip's, in a `… 2>&1 | gzip > dest` pipeline that recorded failed dumps as successful backups and wrote stderr inside the archive). stdout is deflated in memory and written through a checked plain handle; stderr is captured on its own pipe and only ever surfaces in the exception. Failure ⇒ throw + `unlink()` the partial file. **Do not switch this back to `gzopen()`/`gzwrite()`**: zlib reports bytes accepted, not bytes written, so a full disk silently truncates the archive — a `/dev/full` test in `tests/Unit/Services/DatabaseDriverMysqlTest.php` guards it.
- **PDO fallback** (no `mysqldump` binary): schemas are read first, then `MYSQL_ATTR_USE_BUFFERED_QUERY` is turned off for the data phase and rows are written as batched multi-row `INSERT`s (`PDO_INSERT_MAX_ROWS = 100`, `PDO_INSERT_MAX_BYTES = 1 MB`).
- **Archive layout** (`StorageDriver`): archives are built with `tar -C <base> ./<relative>`, base = `storage_path()`, so members read `./app/…` and extract back in place. 1.x archives carry the producing machine's absolute path; `legacyPrefixDepth()` detects them from the member names alone (a `./` prefix ⇒ current format, 0 stripped) and extracts with `--strip-components`. Never key that detection off config or off the local path. Excludes are matched against member names, so they are rewritten relative to the same bases.
- **Restore semantics** (`RestoreService`): filesystem restore **merges** by default. `wipe_storage` empties only the directories in `vanguard.sources.filesystem_paths` (resolved under `storage_path()`, anything escaping it is refused and logged) — `storage_path()` itself is never wiped, and the directory nodes are kept so permissions/symlinks survive. `StorageDriver::extract()`'s own `$wipe` is always false from here: its destination is `storage_path()`.
- **Restore source**: `source` = `local|remote|ftp`; omitted ⇒ first destination whose path column is non-empty (local → remote → ftp). Never default to `local` — production setups often only have the remote copy.
- **Config**: `vanguard.dump.mysql_options` (`VANGUARD_MYSQL_DUMP_OPTIONS`), default `--single-transaction --quick --routines --triggers --no-tablespaces`; accepts a space-separated string **or** an array. `--events` is intentionally excluded (needs the `EVENT` privilege). `DatabaseDriver::DEFAULT_MYSQL_DUMP_OPTIONS` mirrors the default for configs published before the section existed — keep both in sync.

### Artisan Commands
- `vanguard:install` — publishes config and migrations
- `vanguard:backup` — run a backup manually
- `vanguard:restore {id}` — restore from a backup. Options: `--source=local|remote|ftp`, `--database=` (rehearsal: redirects only the `database` key of the target's own connection — the landlord's or, for a tenant backup, the tenant's — for that run; allowlisted identifier), `--no-verify`, `--no-db`, `--restore-storage`, `--wipe-storage` (errors out without `--restore-storage`; asks a second confirmation naming the directories to erase), `--force`
- `vanguard:list` — list backup records
- `vanguard:prune` — delete backups past retention period
- `vanguard:cleanup-tmp` — remove orphaned tmp directories left by crashed workers

The HTTP API (`POST /api/backups/{id}/restore`) exposes `verify_checksum`, `restore_db`, `restore_storage`, `source` — **not** `wipe_storage` nor `database`, which stay CLI-only and are refused with a 400 on presence alone.
