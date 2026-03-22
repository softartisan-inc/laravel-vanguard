# Changelog

All notable changes to `softartisan/vanguard` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-03-18

### Added
- Multi-tenant backup dashboard (Vue 3 SPA + Laravel package).
- Database backup support: MySQL, PostgreSQL, SQLite via `DatabaseDriver`.
- Filesystem backup support: configurable paths and exclusions via `StorageDriver`.
- Backup bundling and storage to local and remote disks (S3-compatible) via `BackupStorageManager`.
- Restore from any backup archive with SHA-256 checksum verification via `RestoreService`.
- Multi-tenancy support via `stancl/tenancy` v3 abstraction (`TenancyResolver`).
- Real-time dashboard updates via Server-Sent Events (SSE) with automatic polling fallback.
- Artisan commands: `vanguard:install`, `vanguard:backup`, `vanguard:restore`, `vanguard:list`, `vanguard:prune`.
- Configurable scheduler (`VanguardScheduler`) with per-tenant schedule overrides.
- Queue support: backup jobs dispatched via `RunTenantBackupJob`.
- Retention policy with automatic pruning of old backups.
- Mail and Slack notifications on backup success/failure.
- `BackupStarted`, `BackupCompleted`, `BackupFailed` events for custom listeners.
- Authentication gate via `Vanguard::auth(Closure)` callback.
- Publishable assets: `vanguard-config`, `vanguard-migrations`, `vanguard-views`, `vanguard-assets`.
- Input validation on filter parameters (`status`, `type`, `tenant_id`, `per_page`) in the backups list API.
- Pre-backup disk space check (minimum 100 MB free) in `BackupManager`.
- System requirements check (`tar`, `gzip`, `mysqldump`, `pg_dump`) in `vanguard:install`.

### Security
- All shell commands use `escapeshellarg()` to prevent command injection.
- Database credentials passed via environment variables (`MYSQL_PWD`, `PGPASSWORD`), never on the command line.
- Temporary directories created with `0700` permissions and cleaned up in `finally` blocks.
- All routes protected by `VanguardAuthenticate` middleware.
