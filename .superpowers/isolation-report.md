# Multi-tenant isolation regression tests

`tests/Feature/TenantIsolationTest.php` (8 tests), backed by test doubles in `tests/Support/`
(`FakeTenant`, `FakeTenancyResolver`, `FakeTenancyManager`, `tenancy_shim.php`). No production
code changed; no dependency added.

## How the fakes work

- `FakeTenancyResolver extends TenancyResolver`, overriding only `isEnabled()` (hard-gated in
  production on `interface_exists(Stancl\Tenancy\Contracts\Tenant::class)`, which is never true
  without stancl/tenancy installed). `allTenants()`, `findTenant()`, `runForTenant()`,
  `tenantDbConfig()`, `landlordDbConfig()` all run as real, unmodified production code.
- `TenancyResolver::runForTenant()` and `RestoreService::restoreTenant()` call the unqualified
  `tenancy()` function. Both classes live in `SoftArtisan\Vanguard\Services`, and PHP resolves an
  unqualified call from inside a namespace against `<namespace>\<name>()` before falling back to
  the global function. `tests/Support/tenancy_shim.php` declares
  `SoftArtisan\Vanguard\Services\tenancy()`, intercepting both call sites without touching
  production code.
- `FakeTenancyManager::initialize($tenant)` mirrors what stancl/tenancy actually does on the two
  axes Vanguard depends on: it writes `$tenant->dbConfig()` into
  `database.connections.{tenant_connection_name}`, and calls `app()->useStoragePath($tenant->storagePath())`.
  `end()` reverses both — the tenant connection is nulled out (not left stale) and storage_path()
  is restored to the landlord's.
- `FakeTenant` is a real Eloquent model over an ad hoc `vanguard_test_tenants` table (created in
  `setUp()`), so `RestoreService::restoreTenant()`'s `$tenantModel::findOrFail()` call works
  unmodified.

## Tests, what they prove, and how each was verified to be able to fail

Every test below was proven able to fail by applying a one-line mutation to the relevant
production file, running the single test with `--filter`, confirming a red result, then reverting
via `cp` from a pre-edit backup (`git diff` confirmed clean after each revert). None of these
mutations were committed.

1. **`backup_tenant_dumps_only_that_tenants_database_config`** — asserts the config
   `BackupManager::backupTenant()` hands to `DatabaseDriver::dump()` for tenant A equals A's own
   config, and is not B's. **Proved red**: commented out `tenancy()->initialize($tenant)` in
   `TenancyResolver::runForTenant()` → `tenantDbConfig()` throws `RuntimeException: Tenant DB
   connection [tenant] not found` instead of returning A's config.

2. **`backup_tenant_archives_only_that_tenants_storage_paths`** — asserts the paths handed to
   `StorageDriver::archive()` are tenant A's `storage_path('app')`, not B's; uses a partial mock
   so `resolveBackupPaths()`/`resolveExcludePaths()` run for real against the tenant-swapped
   `storage_path()`. **Proved red** with the same `initialize()` mutation as above (the DB dump
   that precedes the storage archive throws first).

3. **`restore_tenant_targets_only_that_tenants_database`** — asserts the config passed to
   `DatabaseDriver::restore()` when restoring tenant A equals A's config; includes the negative
   that B's database path never appears anywhere in it. **Proved red**: commented out
   `tenancy()->initialize($tenant)` in `RestoreService::restoreTenant()` → same
   `RuntimeException` from `tenantDbConfig()`.

4. **`restoring_a_tenant_never_enters_the_landlord_connection`** — asserts the restore config
   carries the tenant marker key (a config the landlord connection never has). **Proved red**
   with the same `restoreTenant()` mutation as #3.

   **`restoring_the_landlord_never_enters_a_tenant_connection`** — asserts the config
   `restoreLandlord()` hands to `DatabaseDriver::restore()` never carries a tenant marker.
   **Proved red**: changed `restoreLandlord()` to read `app(TenancyResolver::class)->tenantDbConfig()`
   instead of the landlord connection → `RuntimeException` (no tenant connection configured,
   since no tenant is active during a landlord restore) — confirms a landlord restore that
   started reading the tenant connection would be caught, not silently pass.

5. **`backup_all_tenants_gives_each_tenant_its_own_record_and_archive_name`** — runs
   `backupAllTenants()` with two tenants on one `BackupManager` instance (the run where a shared
   manager state would leak most easily) and asserts distinct archive names, distinct DB config
   markers, distinct `tenant_id`s and distinct `file_path`s across the two resulting records.
   **Proved red** with the same `initialize()` mutation as #1 (second tenant's dump throws,
   `backupAllTenants()`'s per-tenant catch logs it as an error result instead of a record, and
   the subsequent `assertNotSame` on captured names indexes into a missing array key).

6. **`run_for_tenant_ends_the_tenancy_context_even_when_the_callback_throws`** — calls the real
   `TenancyResolver::runForTenant()` with a callback that throws, and asserts the active tenant is
   cleared and `end()` was called anyway. **Proved red**: rewrote `runForTenant()` without the
   `try/finally` (plain sequential calls) → `assertNull(activeTenant())` failed because the tenant
   was still active after the caught exception.

   **`restore_tenant_ends_the_tenancy_context_even_when_the_restore_throws`** — same property for
   `RestoreService::restoreTenant()`, forcing `DatabaseDriver::restore()` to throw. **Proved red**:
   removed the `try/finally` around `tenancy()->initialize()`/`end()` in `restoreTenant()` → same
   failure, active tenant still set after the caught exception.

All 8 mutations were reverted immediately after observing red; `git diff` on each touched
production file was empty afterward, and the full suite (`vendor/bin/phpunit -c phpunit.xml.dist`)
was re-run green (284 tests, 630 assertions, no production files modified) before writing this
report.

## What is not covered here, and why

**"The dump file's bytes contain no other tenant's rows."** No test asserts on actual dump
content. `DatabaseDriver::dump()` is mocked throughout — proving byte-level content isolation
would require a real MySQL/Postgres/MySQL server per tenant, which this suite (SQLite, no shell
dependencies beyond `tar`) does not have. This property is covered by the controller's live check
against MariaDB referenced in the task brief, not by this suite. A future addition could add a
narrow, MySQL-only integration test behind a skip-if-unavailable guard, but that is out of scope
for this pass.

## Things noticed while writing these tests (not fixed — test files only, per constraint)

- `RestoreService::restoreTenant()` calls the global `tenancy()` function directly instead of
  going through the injected `TenancyResolver` (which only supplies `tenantDbConfig()` here via
  `app(TenancyResolver::class)`, a second, separate resolution). `BackupManager` instead routes
  entirely through `TenancyResolver::runForTenant()`. The two code paths achieving tenant-context
  entry/exit through different mechanisms is not a bug (both work, as this suite proves) but it is
  an asymmetry worth flattening at some point — a future refactor to route `restoreTenant()`
  through `runForTenant()` would remove one of the two places `tenancy()` is called directly and
  make the two flows structurally identical.
- Before this pass, no test constructed `RestoreService` with `type => 'tenant'` at all —
  `restoreTenant()` had zero coverage, not just no isolation coverage. This is now exercised by
  tests 3, 4, and 6b above.
