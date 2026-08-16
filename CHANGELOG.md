# Changelog

All notable changes to `softartisan/laravel-vanguard` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.1] — 2026-08-16

Everything below was found by exercising the package against a real MariaDB
server, a real PostgreSQL server, a real Redis queue and real S3-compatible
object storage, rather than against fakes.

### Added
- **Backup notifications actually exist.** `config('vanguard.notifications')` has described mail and Slack alerts on success and failure since 1.0.0, and the changelog listed them as a feature, but no code ever read that config: there was no notification, no mailable, no sender. Setting `VANGUARD_NOTIFY_MAIL` bought silence — which is how a broken destination goes unnoticed for months. The backup outcome events now feed a listener honouring `on_failure`, `on_success`, `mail.to` and `slack.webhook_url`. A channel that throws is logged, never allowed to turn a working backup into a failed one.
- `VANGUARD_LOCAL_ENABLED`, `VANGUARD_LOCAL_DISK`, `VANGUARD_LOCAL_PATH` — see 2.0.0's note; the local destination was hardcoded on.
- `vanguard:install` now names the alert and local-destination variables in its next steps, instead of walking through every setting except the one that says when a backup fails.

### Fixed
- **A file changing during the archive failed the whole backup.** GNU tar exits 1 for "file changed as we read it" and 2 or above for a real failure; every non-zero code was fatal, so a filesystem backup racing its own logs, sessions and uploads failed at random. Archiving keeps the archive on exit 1 and logs the affected members; extraction during a restore still treats any non-zero exit as fatal.
- **PostgreSQL restores were impossible on any password-protected server.** `PGPASSWORD=x gunzip -c file | psql` gives the variable to the first command of the pipeline only, so psql prompted and died with "password authentication failed". The password now goes into the process environment.
- **`pg_dump` still hid its failures behind gzip** — the same defect the MySQL path lost in 2.0. It now runs through `proc_open`, with stderr kept out of the archive and a non-zero exit failing the backup.
- **`vanguard:prune --days=0` was silently ignored** ("0" is falsy), and `--days=abc` was read as 0, pruning every completed backup and deleting its archive from every destination. Reproduced against a real bucket: seventeen backups gone in one command. The option is validated now.
- **`--queue` did nothing.** The flag was declared as "force dispatch to queue even if queue.enabled=false" and read by nobody. It now dispatches for every target, including filesystem backups, via a new `__filesystem__` job sentinel. Without the flag, a hand-typed backup still runs inline — a job nobody works off is the silent no-op this package exists to prevent.
- `BackupStorageManager` no longer opens a temporary directory in its constructor. It is resolved by every dashboard request and every restore, most of which write no temporary file, and each resolution left an empty `0700` directory behind for good — 687 had accumulated on the installation this was found on.

---

## [2.0.0] — 2026-08-15

Three failures this release fixes were silent: a dump that died halfway was still
recorded as a successful backup, filesystem archives restored into a junk tree
instead of their original location, and `composer test` ran nothing at all on a
fresh clone. Read the upgrade guide in the README before upgrading.

### Changed
- **BREAKING — supported stack**: requires PHP `^8.3` and Laravel `^12.0`. PHP 8.1/8.2 and Laravel 10/11 are no longer supported. Laravel 11 is dropped rather than merely untested: every 11.x release carries open security advisories that Composer will not resolve around, and a backup package has no business shipping a pipeline that waives them.
- **BREAKING — filesystem archive format**: archives are now created relative to a base directory instead of storing absolute paths. Archives written by 1.x remain restorable — extraction detects the legacy layout from the archive itself and strips the right prefix, deriving the depth from the member names rather than assuming it.
- **A broken dump now fails loudly.** A backup that 1.x reported as successful may start failing after this upgrade; that is the bug being fixed, not a regression.
- MySQL dumps run through `proc_open` instead of a shell pipeline, with `--single-transaction --quick --routines --triggers --no-tablespaces` by default: writes are no longer blocked during a dump, and stored procedures and triggers are included.
- Tests are declared with the `#[Test]` attribute instead of `/** @test */` doc-comments, which PHPUnit 12 no longer reads.

### Added
- `vanguard:restore --wipe-storage` empties the backed-up directories before extracting, so a filesystem restore can replace instead of merge. Only the paths listed in `vanguard.sources.filesystem_paths` are touched; a configured path that would escape `storage_path()` is skipped and logged. The option refuses to run without `--restore-storage` and asks a second confirmation naming the directories it is about to erase. Merging remains the default, and the HTTP API deliberately does not expose the option.
- `GzipDumpWriter` — the single place that knows how a dump reaches disk, so dump failures cannot be masked by the compression step that follows them.
- Versioned `phpunit.xml.dist`, which is what makes `composer test` work on a clone. `phpunit.xml` stays ignored as a local override, with the ignore rule anchored so it cannot swallow the `.dist`.
- CI matrix covering PHP 8.3, 8.4 and 8.5 against Laravel 12.
- `VANGUARD_LOCAL_ENABLED`, `VANGUARD_LOCAL_DISK` and `VANGUARD_LOCAL_PATH`: the local destination was hardcoded, so a deployment that wanted its backups off the server itself had to publish the config file to say so.

### Fixed
- **`--all-tenants` backed up the first tenant only.** `BackupManager` keeps one `BackupStorageManager` for its lifetime and every backup ends with `cleanTmp()`, which deletes the session tmp directory; the path was computed once in the constructor, so from the second tenant on every dump was written into a directory that no longer existed (`Cannot open dump destination`). The directory is now opened lazily and forgotten by `cleanTmp()`. The queue path hid this, since a dispatched job resolves a fresh manager per tenant.
- **A MySQL restore could not run at all.** The restore command was built from `mysqlConnectionArgs()`, which appended `--single-transaction --quick --lock-tables=false --set-gtid-purged=OFF`. Those are mysqldump options; the `mysql` client rejects them as unknown and exits before reading a statement. Dump options now live only in `mysqlDumpOptions()`.
- **Restoring the landlord database rewound the backup catalogue.** The dump contains `vanguard_backups` itself, captured while the backup was still running, so after a restore that backup came back as `running` — and was then refused on the next restore — while every backup taken after the dump disappeared from the catalogue though its archive still existed. The rows are now preserved across the restore.
- **A failed dump was recorded as a successful backup.** The MySQL dump ran as `mysqldump ... 2>&1 | gzip > dest` through a shell, so the exit code checked was gzip's, not mysqldump's. A dump that died halfway still produced a file, a checksum and a `completed` record — with the error message written *inside* the `.sql.gz`. Discoverable only at restore time.
- **A filesystem restore never put a single file back in place.** `tar` was handed absolute paths with no `-C`, so members were stored as `var/www/html/storage/app/...`; extracting with `-C storage_path()` recreated that whole chain, leaving files at `storage/var/www/html/storage/app/`. Archiving and extraction are now symmetric. Excludes are rewritten relative to the same base, since `tar` cannot match absolute exclude patterns against relative members.
- The PDO fallback used when `mysqldump` is unavailable now streams unbuffered (`MYSQL_ATTR_USE_BUFFERED_QUERY = false`). Its comment claimed row-by-row streaming while PDO MySQL buffers by default, loading whole tables into memory before iterating.
- `composer test` ran `phpunit` with no arguments while `phpunit.xml` was gitignored: on a fresh clone PHPUnit found no configuration, printed its help and exited without running anything. The suite also used doc-comment annotations exclusively — 209 `@test`, not one test-prefixed method — so a PHPUnit 12 upgrade would have collected zero tests while CI reported success.
- Install instructions named `softartisan/vanguard`; the package has been `softartisan/laravel-vanguard` since it was renamed.

---

## [1.2.0] — 2026-08-15

### Fixed
- **A backup stored only on a remote destination could not be restored.** `vanguard:restore` had no `--source` option and `RestoreService` fell back to `local`, so it looked for a `file_path` that is empty whenever local storage is disabled, failing with `No file path available for backup #X on destination [local]`. The command now takes `--source=local|remote|ftp` and, without it, reads the bundle from the first destination the backup actually reached.

---

## [1.1.0] — 2026-03-24

### Added
- **IoC extension guide**: documented how to swap `BackupManager`, `DatabaseDriver`, `StorageDriver`, `TenancyResolver`, and `VanguardScheduler` via the Laravel container, including a note on why `BackupManager` must remain transient (`bind`, not `singleton`).
- **Per-tenant schedule customization**: documented the `vanguard_schedule` column approach and the `TenancyResolver::tenantSchedule()` override pattern (e.g. UTC-adjusted cron from tenant timezone).
- **Multiple landlord schedules**: documented how to extend `VanguardScheduler` to register several independent cron entries for the landlord (e.g. nightly DB-only + weekly full backup).

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
