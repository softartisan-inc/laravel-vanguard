# Changelog

All notable changes to `softartisan/laravel-vanguard` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.3.0] — 2026-08-16

The dashboard stops being a screen that describes the configuration and starts
being one that reports what actually happened. Restores are queued, recorded
and alarmed like backups; the health screen answers "is it working" with
evidence rather than settings; and every destructive action leaves a trace
naming who asked for it.

**Read this before upgrading.** The restore endpoint's contract changed in a
way that breaks any client written against 2.2.0, dashboard-triggered tenant
backups quietly got bigger, and a new archiving guard can drop a symlinked
`filesystem_paths` entry. All three are spelled out below.

### Changed
- **BREAKING — the restore endpoint.** `POST /vanguard/api/backups/{id}/restore` now:
  - **requires `confirm`**, a string repeating the target's name exactly — the tenant id, or `landlord` / `filesystem` for the untenanted targets. Anything else is refused with `400`. This is an API rule, not an interface courtesy: a curl call is refused the same way the dashboard's disabled button refuses a click, because a restore overwrites a live database and a `--days` typo really did erase seventeen backups during the 16 August tests.
  - **answers `202` with `{"restore_id": N, "status": "pending"}`** instead of `200` with a result. Nothing has been restored when the call returns; the work runs on a worker and the row is where the outcome lives.
  - **answers `400` for a backup that is not `completed`**, rather than attempting it. One convention for every business refusal: `400` for a rejection on the merits, `422` for a request whose shape is wrong.
  - **refuses `wipe_storage` on presence alone**, whatever its value. Replace mode does not destroy what the backup contains — it destroys what the backup does *not* contain, with no way back — and stays a console decision. A caller sending `wipe_storage=false` is told the parameter has no meaning here rather than silently obeyed.
  - **has no synchronous path left.** The old one ran a multi-minute operation inside the request, lost its answer to the first proxy timeout while the server carried on regardless, wrote no history, and hid the exact error behind "check server logs". Keeping both would have left two ways to restore with two different levels of observability — the mistake this release exists to remove.
- **Dashboard-triggered *tenant* backups now include the filesystem by default.** This is the one behaviour change with no visible cause, so it is called out rather than buried: `run()` used to dispatch `[]`, and `BackupManager::backupTenant()` defaults `include_filesystem` to `false`, so the dashboard produced database-only tenant archives. It now sends `include_filesystem => true` unless told otherwise, which is correct parity with `vanguard:backup --tenant=` (where the filesystem is included unless `--no-filesystem` is passed) — but it can turn a 200 MB tenant archive into a multi-gigabyte one overnight. Pass `"include_filesystem": false` to get the old shape.
- **Check your symlinked storage paths after upgrading.** The new archiving guard resolves each `filesystem_paths` entry through `realpath()` and refuses anything landing outside `storage_path()`. That is deliberate — the read side had no guard at all while the destructive side did — but it means an entry that is a *symlink pointing out* of `storage_path()` (a `storage/app/public` aimed at a shared volume, a common Docker layout) is now dropped from the archive rather than followed. The boot-time validator inspects the configured string and cannot see through a symlink, so nothing will warn you: confirm your archives still contain those trees.
- The health endpoint has its own rate limiter, `vanguard.health`, reading the new `rate_limits.health` key (`VANGUARD_RATE_LIMIT_HEALTH`, 12/min). It shared the 5/min `vanguard.run` bucket, and a named limiter is keyed by name and user rather than by route — so five loads of the landing page `429`'d the backup trigger, the download, and the health page itself.

### Added
- **A health endpoint, `GET /vanguard/api/health`** — the screen that answers "is it actually working" from evidence rather than configuration, which is what the old dashboard answered from, wrongly, for five months in 2026. A destination is writable because a fourteen-byte witness object was just written to it, read back and deleted; a cron is alive because it left a stamp; a target is fresh because a backup of it completed. Alert channels report `set` / `absent` and never the value — a Slack webhook URL is a credential, and this payload reaches a browser. Every section is guarded on its own, so one broken part degrades to `null` with a reason instead of emptying the page.
- **`vanguard:restore --database=` — a restore you can rehearse.** A restore nobody has ever run is a backup nobody has ever verified, and until now nobody could run one without meaning it: the target was `config('database.default')` and no option moved it, so the only way to try an archive was to repoint the whole application at a scratch database. The person who checked that these archives actually restore could not use the command at all and had to call the service by hand. `--database=vanguard_rehearsal` now redirects the restore — landlord and tenant paths alike — for that run only: the host, credentials and driver of the real target are kept, so the rehearsal exercises the same server and the same client binary, and only the database written into moves. Nothing outside the call is reconfigured. The value must be a plain identifier (`^[A-Za-z0-9_.\-]+$`), refused rather than escaped, because it is interpolated into the `mysql`/`psql` command line; and the console says where it is writing twice, in the metadata table and again on its own line before the prompt, because a rehearsal that silently hits production is worse than no rehearsal.
- **A restore history, `GET /vanguard/api/restores` and `/restores/{id}`** — what each restore targeted, which copy it read, with which options, who asked, its live phase, and on failure the exact error the HTTP layer refuses to disclose.
- **`POST /vanguard/api/prune` and `POST /vanguard/api/cleanup-tmp`** — parity with `vanguard:prune` and `vanguard:cleanup-tmp`, including `days=0` read on presence rather than truthiness. Both sit behind the same typed confirmation as a restore.
- **`GET /vanguard/api/backups/{id}/download`** streams the archive from whichever destination actually holds it, ordered local → remote → ftp with no default of `local`: on the recommended production setup local is disabled and only the remote copy exists. Streamed, never loaded — a landlord archive is gigabytes.
- **Every destructive action is traced.** Restore, prune, delete and download all log at *warning* level, naming the actor and the target: an audit trail a production `LOG_LEVEL` silently discards is not an audit trail. Download is included because it takes every tenant's database, personal data and all, off the server.
- **The scheduler proves it is running.** Nothing in an installation could tell a live cron from a dead one; in March 2026 this product showed a flawless configuration and backed up nothing for five months. Every scheduled Vanguard command now stamps a heartbeat *in the scheduler process, before* the background task is spawned — it is the scheduler running that is being proved, not the command succeeding — and the health screen reports `alive` only if the stamp is newer than twice the backup interval.
- The dashboard can now choose the backup source and force the queue: `include_filesystem` (i.e. `--no-filesystem`) and `queue` (i.e. `--queue`) reach the API, which used to drop both on the floor.
- The restore dialog in the shipped bundle asks the operator to type the target's name back and stays inert until it matches, mirroring the server rule instead of discovering it through a `400`. Its toast reports a *queued* restore and names the `restore_id`.

### Fixed
- **The SSE change detector was blind to whole classes of change.** It hashed a status→count map plus the maximum id — a lossy aggregate. Any set of transitions leaving both the multiset of statuses and the highest id unchanged produced no event, which is exactly the shape of `--all-tenants` on a single worker; and it never read `vanguard_restores` at all, so every restore and every phase of every restore was invisible to the channel the restore screen is built on. The fingerprint is now a hash of `id:status:updated_at` over a bounded window of both tables: exact for any state change, and it catches creations and deletions. The event is still named **`backup.updated`** and now fires for restores too — renaming it would ship a dashboard that silently stops updating, so the name waits for phase 3 to rebuild the bundle that reads it.
- **A refused local write was recorded as a destination the backup reached.** `persistToLocalDisk()` returned void and dropped the stream fallback's `false`, so `local_path` was set unconditionally. The fallback is the cross-filesystem case — tmp on tmpfs, storage on a mounted volume, the normal Docker layout — and the record then claimed a copy that did not exist, which a later download or restore answered `404` on. It now raises the way the remote and FTP destinations always have.
- **A filesystem backup turned the landlord freshness row green.** Both carry a null `tenant_id`, so selecting on that alone let a manually triggered filesystem run satisfy the only indicator on the health screen that turns red on its own — while the central database had not been dumped in weeks. The landlord row is constrained to `type = 'landlord'`.
- **A cache outage `500`'d the health page.** The heartbeat read sat outside its `try` while every other section was guarded. On this product the cache is Redis, and a Redis outage is precisely when someone loads the page that reports breakage.
- **`/api/health` ran one query per tenant.** Two hundred tenants was two hundred reads per load of the landing page. It is one grouped `MAX(completed_at)` for the whole tenant list plus the landlord row: two queries, whatever the customer list looks like.
- **Vanguard's own tables are read through `Vanguard::centralConnection()` everywhere.** Seven sites still resolved the connection themselves — the stats, listing, tenants and delete endpoints, `pruneOldBackups()`, and both sites in `RunRestoreJob`. The job was the worst: it used `config('tenancy.database.central_connection', config('database.default'))`, which answers `null` for a key that is present but null, and `RestoreRecord::on(null)` then re-resolved `database.default` *at query time* — from inside the tenancy window, the one place the connection swap is guaranteed active. Restore phases were written to the tenant database, aborting the restore.
- **The health endpoint no longer dies with the central database.** Each section degrades on its own, so an unreachable catalogue still leaves the destination probes, the schedule and the queue readable — that outage is the one this page exists to surface.
- **An unreachable Redis hung the health page for half a minute.** The queue depth was read through the application's own connection, so a driver that is silent rather than absent left the request sitting in the client's connect timeout — the page that reports breakage, hanging exactly when things are broken, which is worse than an error. The probe now reads through a private connection of its own with a one-and-a-half second bound: a real backlog is still reported truthfully, an unreachable driver answers `null` with the connection's own timeout as the reason, and the application's connections are left untouched. Measured on a live installation: thirty seconds before, two after. Drivers other than Redis, and Redis clusters, fall back to the ordinary read — unbounded but correct, because a probe that reports nothing on a working system is worse than a slow one.
- `GET /api/backups/{id}/download?source=bogus` answers `422` even without an `Accept: application/json` header. `validate()` only answers `422` for JSON callers, and a direct browser navigation to a download link — this endpoint's normal invocation, not an edge case — was redirected instead of told the value was bad.

### Fixed — hardening ported from the March stash
Five fixes written in March 2026 and never merged. They had had no independent
review until this release.

- **A tenant key could be injected into a scheduled command line.** The scheduler interpolates the key into a string it hands to a shell. A key is now refused unless it is a plain identifier — an allowlist rather than escaping, because a tenant key carrying a space, a newline or a shell metacharacter has no business on a command line, and quoting it would only hide how odd it is.
- **A backup record could be given a final status without ever having run.** Only a `running` record may be marked completed or failed, so a stray callback cannot resurrect a record into a state that never happened.
- **A `filesystem_paths` entry could escape `storage_path()` when archiving.** A stray `..` or an empty string named the server directory itself and the archive quietly became everything on the machine. The read side had no guard while the destructive side did; see the symlink note under **Changed** for the cost of this one.
- **One database blip tore down every open dashboard.** The SSE poll reconnects inside its loop, so a database restarted between two polls raised out of the streamed response and every connected client lost its stream at once — over an interruption worth a single cycle. A failed poll is now a log line and a heartbeat.
- **Leaked file descriptors, an unbounded listing, and a query per tenant.** Upload and download handles are closed in `finally` blocks (a Horizon worker running a backup an hour leaked one per failure until it could not open a file at all), `vanguard:list` is bounded, and `/api/tenants` reads the whole screen in a fixed number of queries instead of two per tenant.

### Deliberately not included
- **`--wipe-storage` has no endpoint.** Replace mode destroys what the backup does not contain, and no confirmation typed into a browser is worth that. It stays a console decision, taken by someone logged into the server, and `RunRestoreJob` hardcodes `wipe_storage => false` so no queued path can reach it either.
- **`vanguard:install` has no endpoint.** It publishes config, runs migrations and inspects the host system; exposing it over HTTP would mean a dashboard that can rewrite its own installation. Its config-drift report is a thing to read on a terminal during an upgrade, not a button.

### What was proven outside the suite, and what was not
The suite is green against SQLite, fakes and a synchronous queue, which cannot
establish this release's central claims. They were therefore exercised by hand
against a live installation — a real MariaDB, a real Redis, real archives —
before this version was tagged. What that run showed:

- **Backups, restores and tenant isolation, on a real database.** The archive's checksum matched what the command reported, its dump decompressed to exactly the live table count with real rows, a restore into a throwaway database reproduced every table, and two tenants dumped side by side shared no byte of each other's data.
- **The queue depth against a real driver.** With one job genuinely queued, `queue.pending` reported it; against an unreachable Redis it answered `null` with the connection's own timeout as the reason, in about two seconds where the unbounded read took thirty.
- **The scheduler stamp crossing processes.** Written by `schedule:run` in a console process, read back by another process through the shared Redis, and turning `alive: false` again once the stamp aged past twice the interval.
- **The alerts, on real failures.** A dump forced to fail dispatched the backup-failure notification; a restore forced to fail dispatched the restore one. Neither was inferred from a green test.
- **A restore rehearsal.** `vanguard:restore --database=` filled a throwaway database while the production one was left byte-for-byte untouched.

Two claims remain unproven, and they are yours to check on your own installation:

- **The write probe against a real object store.** It was proven against a real filesystem disk — the round trip happens, the witness object is removed, an unwritable path reports its true reason — but no S3-compatible endpoint was reachable in the verification environment. The exact failure this endpoint exists to catch, a bucket that accepts a configuration, a listing and a HEAD while refusing every PUT, is still the one nobody here could reproduce. Load `/api/health` against your real destinations and confirm `writable: true` is being *earned*.
- **The tenancy switch around a tenant backup.** Isolation was proven at the level of the dump itself; the `stancl/tenancy` connection swap that wraps it could not be exercised without writing a tenant into the live catalogue. If you run per-tenant backups, confirm one archive against one tenant's data before trusting the set.

---

## [2.2.0] — 2026-08-16

Restores get what backups already had: a record of what happened, and an alarm
when it doesn't.

**Read this before upgrading:** this release wires the machinery, not the
button. The dashboard's restore endpoint still runs synchronously and still
creates no history row, so a failed restore triggered from the UI does **not**
yet alert anyone. That arrives when the API switches to the queued path.

### Added
- **A restore history.** The new `vanguard_restores` table records every restore: what it targeted, which copy it read, with which options, who asked, when it started and finished, and — on failure — the exact error, not the "check server logs" the HTTP layer returns. The target is copied onto the row rather than read through the relation, so the history outlives the backup it restored.
- **`RunRestoreJob`** runs a restore off the queue Vanguard already uses. A restore of a live tenant runs for minutes; inside an HTTP request that answer is lost to the first proxy timeout while the server carries on regardless. One attempt only — replaying a partial write into a live database is not a retry — and a `failed()` handler so a killed worker or an expired timeout still ends the row and raises the alarm instead of leaving it `running` for ever.
- **Live phases.** `RestoreService` announces `downloading`, `verifying`, `unpacking`, `restoring database` and `restoring files` through an optional callback. Deliberately not a percentage: nothing in the chain reports real progress, and an invented percentage is a false statement. Without a callback the service behaves exactly as before, so the console path is untouched.
- **`Vanguard::restoreActor()`** names the operator behind a restore. The package cannot presume the host application's user model, and "who restored the production database" is the first question asked afterwards.
- **A failed restore raises the same alarm as a failed backup**, through the same listener, config keys and channels. A restore is attempted when something has already gone wrong; failing it silently leaves the operator believing the recovery worked.

### Fixed
- **A landlord restore erased the restore history.** `preservingCatalogue()` protected `vanguard_backups` and nothing else, so the operation deleted the record of itself. Found by restoring against a live database — no unit test could see it, since they mock the service.
- **Restore bookkeeping is pinned to the central connection.** An unpinned Eloquent model re-resolves `database.default` on every write, so a tenancy swap or a worker with a stale default sent the history write to a database where the table does not exist — aborting the restore before it began.
- **An oversized error message suppressed the alert it was reporting.** Unbounded stderr written to a `text` column throws from inside the catch block, so the failure event never fired. The message is truncated at storage, and a failure to persist no longer prevents the alarm.
- Notifications carry the first line of an error, capped, instead of the whole stderr: database client errors routinely name the host and the user, and until now that text stopped at the log file.

---

## [2.1.0] — 2026-08-16

### Added
- **`vanguard:install` reports config drift.** `vendor:publish` never overwrites an existing `config/vanguard.php`, so upgrading leaves an older config in place and the keys added since are simply absent. Every read has a default, which sounds harmless until the default is "empty": a config published before 2.0.1 has no `notifications` block, so `mail.to` resolves to null and no alert is ever sent. The command now names each missing setting, or confirms the config is current.

### Fixed
- `vanguard:install` pointed at a repository name the package no longer uses.

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
