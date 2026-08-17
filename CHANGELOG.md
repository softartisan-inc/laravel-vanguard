# Changelog

All notable changes to `softartisan/laravel-vanguard` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.3.1] — 2026-08-17

The four things 2.3.0 shipped without, plus the two defects that running this
release against a real preprod installation turned up — a real MariaDB, a real
Redis, a real Hetzner bucket, `stancl/tenancy` active. One of the four is a
credential leak that had already been fixed once, in March 2026, on a branch
nobody merged; one of the two is an archive that captured nothing and said
`✅ Completed`.

**Read this before upgrading.** How the MySQL and MariaDB clients are invoked
has changed — the password now arrives in a temporary defaults file instead of
the environment. Nothing about your configuration changes, but the client now
reads one more option file, and it is the option file where an unusual
`my.cnf` and an awkward password would show up. Run one backup **and one
restore** after upgrading rather than assume; a dump that cannot authenticate
fails loudly, but only once it runs.

**And then read your own backups.** This release can tell you that an archive
carries no file; it cannot tell you that the archives you already have do. Run
`php artisan vanguard:backup --tenant=<id>` once per tenant and look for the
new warning: any tenant it fires on has been backed up without a single file
for as long as its storage layout has not matched
`vanguard.sources.filesystem_paths`, and the health screen's freshness has been
green throughout.

### Security
- **The MySQL password no longer travels through the process environment.** It was passed in `MYSQL_PWD`, which is not the private channel it looks like: the environment of a running process is readable, through `/proc/<pid>/environ`, by anything running as the same user — a second PHP-FPM pool, a stray shell, any compromised dependency — for as long as the dump lasts. And because it was set on the *worker* rather than on the child, a fatal signal, which skips every `finally` block, left it set for every process started afterwards. It now goes into a temporary file created `0600` before the secret is written to it, handed to the client with `--defaults-extra-file=` — which has to be the client's *first* argument, or it is rejected as an unknown variable — and deleted in a `finally` block on the dump and the restore alike, including when either throws. There is no `MYSQL_PWD` left anywhere in the package. The password has never been on the command line, where `ps` would show it to every user of the machine, and still is not.
- **A password containing `#`, a quote, a backslash or a leading or trailing space now survives.** The defaults file is ini-shaped: unquoted, a `#` starts a comment and the surrounding spaces are stripped, so the client would have authenticated with a truncated password and the backup would have died with "Access denied" — indistinguishable from a wrong password, and a very expensive night to diagnose. The value is written double-quoted with its backslashes and quotes escaped, which is pinned by a test that asks the real `mysqldump` binary, through `--print-defaults`, what it read back.

### Fixed
- **A backup could archive nothing and report success.** Observed for real: `vanguard:backup --tenant=9`, filesystem included as it is by default, produced an archive whose filesystem member held **zero files**, and the command printed `✅ Completed`. The cause is configuration-shaped and it is the whole point — `sources.filesystem_paths` is `['app']`, `stancl/tenancy` swaps `storage_path()` to the tenant's own root for the duration of the backup, and that root had no `app/` directory, so the path list resolved empty, `tar` was handed nothing and wrote a valid, tiny, empty tarball. The mechanism was working; the *silence* was the defect. An archive that looks healthy, weighs almost nothing and restores nothing is the exact failure this package exists to abolish: the tenant was invisibly unprotected and the health screen's freshness row was green on it. A filesystem backup that resolves no existing path now logs a warning naming the target, the configured paths and the resolved storage root; prints that warning on the console; and marks the record (`meta.filesystem_empty`, returned by the API as `filesystem_empty`) so a dashboard reading a list of green rows can say which of them carry no file. It is **not** a hard failure by default — an installation that genuinely keeps nothing under `storage/app` is legitimate, and turning a working setup into a failing one on upgrade would be worse than the silence. See `VANGUARD_ON_EMPTY_FILESYSTEM` under **Added** for the installations where it should be. **What to check:** run one backup per tenant and see which ones warn.
- **Restoring an empty filesystem member reported a successful restore of nothing.** The mirror of the same defect, and the more expensive half: the operator is standing in front of an incident believing their files came back. A storage member holding no file is now named in the log and on the console before anything is extracted, and the phase context carries it so the restore history and the dashboard see it too. `--wipe-storage` is **refused** in that case rather than obeyed: erasing your live directories to replace them with an empty archive is not a restore, and it is precisely the destruction an archive taken before this fix would have caused. An archive that cannot be *listed* is not reported as empty — unreadable is a different claim, and the extraction that follows fails with the real error.
- **The console hid where the archive went.** On the setup the documentation recommends — `VANGUARD_LOCAL_ENABLED=false`, remote only — `vanguard:backup` printed `Path :` followed by nothing, because it printed `file_path`, the *local* copy, which does not exist there. The operator was told the size and the checksum of a file they had no way to find. The result now names **every destination the archive actually reached**, with its path, in the order local → remote → ftp, says plainly when a destination was configured and not reached, and calls out in red a backup that reached none at all — which used to print as an empty line under a green "Completed". The same blind spot is closed in `vanguard:list`, which gained a **Stored on** column (`nowhere` in red for a completed record that reached nothing), and in `vanguard:restore`, whose metadata table now says which destinations hold the archive and which one it is about to read. **What to check:** `php artisan vanguard:list` — any row reading `nowhere` is a catalogue entry with no archive behind it.
- **The health screen contradicted itself on the "twice the interval" rule.** A heartbeat landing exactly on twice the backup interval was reported dead, while a backup landing exactly on twice the same threshold was reported fresh: one page, one rule, two answers, and an operator with a red cron beside a green freshness row on the same timestamp had no way to tell which of the two was lying. The boundary is inclusive on both now — twice the interval is what a run is allowed to slip by, and a run that slipped by exactly that has not missed anything yet. The alarm belongs one second later.
- **An enabled destination with no disk was indistinguishable from a disabled one**, and it is the more dangerous of the two. Both reported `writable: null, reason: null`, the shape that means "not probed, nothing claimed" — so the screen said nothing was wrong with a destination the operator had switched on and which could not possibly work. It is now a named failure telling you which key to set. On the write side the same value was passed straight through: a *blank* disk name — what `VANGUARD_REMOTE_DISK=` in a `.env` produces, the key being present so the config default never applies — is falsy, and `Storage::disk('')` answers with the application's **default** disk, so archives landed somewhere nobody had chosen while the record claimed the destination; a null one crashed several frames down naming an argument instead of the setting. Both are refused by name before a byte is written. If a backup starts failing after this upgrade with "Destination [...] is enabled but names no disk", it was already writing to the wrong place — check where your last archives actually went.
- **The delete confirmation in the dashboard was still the browser's `confirm()`.** It could say no more than "#41" — not which target, not which date — and it is the one dialog a browser may stop showing: after a few of them Chrome offers to suppress further prompts from the page, and a suppressed `confirm()` returns false silently, so the delete button would simply have stopped working with nothing to explain why. It now uses the same modal the restore path uses, naming the target and the date the archive was taken. It does **not** ask for the target's name to be typed back: that guard belongs to restore and prune, which overwrite or erase data you still have, and asking for it everywhere is how operators learn to type through confirmations without reading them.

### Changed
- **`POST /vanguard/api/backups/{id}/restore` refuses `database` on presence alone**, the way it already refuses `wipe_storage`, with a `400` naming the console command instead. It was previously ignored — silently, which is the failure mode this option cannot afford: a dashboard user who believes they are rehearsing into a scratch database, and is quietly given the real one, gets the worst outcome the feature has. Redirecting a restore stays something you do at a console, in front of the machine. Nothing carries it into the queued path either: `RunRestoreJob` builds its options from the history row, and there is no column that could hold one.

### Added
- **`VANGUARD_ON_EMPTY_FILESYSTEM`** (`sources.on_empty_filesystem`) — what a backup should do when it was asked for the filesystem and not one of the configured paths exists. `warn`, the default, completes the backup and says so everywhere it can; `fail` refuses it. Set `fail` on an installation where an empty filesystem archive can only mean a misconfiguration — a tenant that certainly holds uploaded documents, for instance. It is deliberately not the default: the flag exists so that the installations which know better can say so, not so that an upgrade can start failing backups nobody asked it to judge.
- **`--database=` is documented.** It shipped in 2.3.0 without a line in the README. Its tenant path is now pinned as tightly as its landlord one: that the redirect applies to the *tenant's own* connection and not to the landlord's, that the tenant's host, port and credentials come through unchanged, and that the connection `stancl/tenancy` installed is left exactly as it was — a rehearsal must not repoint the tenancy window it borrows. Each of those tests was checked against a deliberate mutation of the code it covers, so none of them is green by accident.

### Verified against a live preprod installation
Every fix above was exercised on a real installation before this was tagged —
MariaDB, Redis, a Hetzner S3 bucket, `stancl/tenancy` active:

- one landlord backup **and** one restore through the new credential file, authenticating against the real server: 45 tables written into a throwaway database, the production one untouched;
- the console naming the destination it reached (`Remote : vanguard-backups/…`) on the local-disabled setup where it used to print an empty line, and `vanguard:list` showing `nowhere` for a completed record whose archive is gone — a real catalogue entry that had been sitting there unnoticed;
- the empty-archive warning firing on a real tenant whose storage root holds no `app/` directory, naming the root and the configured paths, with `filesystem_empty` recorded on the row.

One thing that campaign found is not a package defect and cannot be fixed from
here: on that installation nothing had run `schedule:run` since March, so two
tenants had never been backed up and a third not since 24 March — while the
configuration looked perfect. `vanguard:backup --all-tenants`, the command the
schedule registers, worked on the first try. Check `schedule.alive` on
`/api/health` before trusting any of this: the package can now tell you the
cron is dead, but only if you look.

### What a green suite still cannot tell you
The credential change is exercised against stub binaries and, for the quoting
decision, against the real `mysqldump` parser — but no test here authenticates
against a live MySQL server. Two things are therefore yours to confirm on your
own installation, once, right after upgrading:

- **One backup and one restore of a MySQL or MariaDB target.** The client now reads an additional option file, and `--defaults-extra-file` is *additive*: your system `my.cnf` and `~/.my.cnf` are still read, and still lose to ours on `password`. If you keep credentials or a `[client]` section of your own there, this is the run that proves the two compose the way you expect.
- **The same, if your password contains anything unusual.** `#`, quotes, backslashes and edge spaces are handled deliberately and tested against the real parser; the account you actually use is the one nobody here could try.
- **Which of your tenants produce an empty archive.** The suite proves that an empty resolution warns and a non-empty one does not; it runs on fakes, without `stancl/tenancy`, so it cannot know what `storage_path()` becomes on your installation once a tenant is initialised. That answer only exists on your machine, and one backup per tenant is what asks it.

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
