<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyManager;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenant;
use SoftArtisan\Vanguard\Tests\TestCase;

require_once __DIR__.'/../Support/tenancy_shim.php';

/**
 * Multi-tenant isolation regression tests.
 *
 * This package's single most consequential guarantee is that a tenant's
 * backup/restore never touches another tenant's data, or the landlord's.
 * Nothing exercised that before this file — see .superpowers/isolation-report.md
 * for what each test proves and how it was verified to be able to fail.
 *
 * stancl/tenancy is a suggested package, not a dependency, so it is not
 * installed here. TenancyResolver::isEnabled() is hard-gated on stancl's
 * Tenant interface existing, which it never does in this environment.
 * FakeTenancyResolver (tests/Support/) overrides only that one check, so
 * runForTenant(), tenantDbConfig(), landlordDbConfig() and allTenants() run
 * as the real, unmodified production code. FakeTenancyManager
 * (tests/Support/) plus tenancy_shim.php intercept the unqualified
 * tenancy()->initialize()/end() calls TenancyResolver and RestoreService
 * make directly, simulating stancl's real effect: swapping the tenant DB
 * connection config and repointing storage_path().
 */
class TenantIsolationTest extends TestCase
{
    private string $landlordStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        Schema::create('vanguard_test_tenants', function ($table) {
            $table->string('id')->primary();
            $table->string('db_database');
            $table->string('storage_path');
        });

        config(['vanguard.tenancy.tenant_model' => FakeTenant::class]);
        config(['vanguard.tenancy.enabled' => true]);
        config(['tenancy.database.tenant_connection_name' => 'tenant']);

        $this->landlordStoragePath = $this->app->storagePath();

        FakeTenancyManager::reset();
        FakeTenancyManager::instance()->boot('tenant', $this->landlordStoragePath);

        $this->app->instance(TenancyResolver::class, new FakeTenancyResolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        FakeTenancyManager::reset();
        $this->app->useStoragePath($this->landlordStoragePath);
        parent::tearDown();
    }

    /**
     * Create a tenant backed by a real DB row (so findOrFail() in
     * RestoreService::restoreTenant() works) with its own distinct fake DB
     * config and its own real directory to back storage_path().
     */
    private function makeTenant(string $id): FakeTenant
    {
        $root = sys_get_temp_dir().'/vanguard_isolation_test/'.$id;
        @mkdir($root.'/app', 0777, true);

        return FakeTenant::create([
            'id' => $id,
            'db_database' => "/fake/{$id}.sqlite",
            'storage_path' => $root,
        ]);
    }

    private function bundleResult(string $name): array
    {
        return [
            'local_path' => "vanguard-backups/{$name}.tar",
            'remote_path' => null,
            'ftp_path' => null,
            'size' => 10,
            'checksum' => hash('sha256', $name),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 1. A tenant backup dumps that tenant's database, not another's.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function backup_tenant_dumps_only_that_tenants_database_config(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $db = Mockery::mock(DatabaseDriver::class);
        $capturedConfigs = [];
        $db->shouldReceive('dump')->twice()->andReturnUsing(function ($driver, $config, $dest) use (&$capturedConfigs) {
            $capturedConfigs[] = $config;

            return $dest;
        });

        $storage = Mockery::mock(StorageDriver::class);

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_iso/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldReceive('bundle')->twice()->andReturnUsing(fn ($files, $name) => $this->bundleResult($name));

        $manager = new BackupManager($db, $storage, $store, new FakeTenancyResolver);

        $manager->backupTenant($tenantA);
        $manager->backupTenant($tenantB);

        $this->assertSame($tenantA->dbConfig(), $capturedConfigs[0]);
        $this->assertSame($tenantB->dbConfig(), $capturedConfigs[1]);
        $this->assertNotSame($tenantB->dbConfig(), $capturedConfigs[0]);
        $this->assertNotSame($capturedConfigs[0]['database'], $capturedConfigs[1]['database']);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. A tenant backup archives that tenant's storage path, not another's.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function backup_tenant_archives_only_that_tenants_storage_paths(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('dump')->twice()->andReturn('/tmp/db.sql.gz');

        // makePartial(): resolveBackupPaths()/resolveExcludePaths() run for
        // real (reading the real storage_path(), swapped per tenant by
        // FakeTenancyManager::initialize()); only archive() is intercepted.
        $storage = Mockery::mock(StorageDriver::class)->makePartial();
        $capturedPaths = [];
        $storage->shouldReceive('archive')->twice()->andReturnUsing(function ($paths, $exclude, $destination) use (&$capturedPaths) {
            $capturedPaths[] = $paths;

            return $destination;
        });

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_iso/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldReceive('bundle')->twice()->andReturnUsing(fn ($files, $name) => $this->bundleResult($name));

        $manager = new BackupManager($db, $storage, $store, new FakeTenancyResolver);

        $manager->backupTenant($tenantA, ['include_filesystem' => true]);
        $manager->backupTenant($tenantB, ['include_filesystem' => true]);

        $this->assertSame([$tenantA->storagePath().'/app'], $capturedPaths[0]);
        $this->assertSame([$tenantB->storagePath().'/app'], $capturedPaths[1]);
        $this->assertNotEquals($capturedPaths[0], $capturedPaths[1]);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Restoring tenant A targets A's database only.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function restore_tenant_targets_only_that_tenants_database(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $record = $this->makeRecord([
            'type' => 'tenant',
            'tenant_id' => $tenantA->getTenantKey(),
        ]);

        $db = Mockery::mock(DatabaseDriver::class);
        $capturedConfig = null;
        $db->shouldReceive('restore')->once()->andReturnUsing(function ($driver, $config, $source) use (&$capturedConfig) {
            $capturedConfig = $config;
        });

        $storage = Mockery::mock(StorageDriver::class);
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/tenant-a_db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        $restoreService = new RestoreService($db, $storage, $store);
        $restoreService->restore($record, ['verify_checksum' => false]);

        $this->assertSame($tenantA->dbConfig(), $capturedConfig);
        $this->assertNotSame($tenantB->dbConfig()['database'], $capturedConfig['database']);
        $this->assertStringNotContainsString($tenantB->getTenantKey(), (string) $capturedConfig['database']);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Tenant restore never enters the landlord connection, and vice versa.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function restoring_a_tenant_never_enters_the_landlord_connection(): void
    {
        $tenantA = $this->makeTenant('tenant-a');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenantA->getTenantKey()]);

        $db = Mockery::mock(DatabaseDriver::class);
        $capturedConfig = null;
        $db->shouldReceive('restore')->once()->andReturnUsing(function ($driver, $config) use (&$capturedConfig) {
            $capturedConfig = $config;
        });

        $storage = Mockery::mock(StorageDriver::class);
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        $restoreService = new RestoreService($db, $storage, $store);
        $restoreService->restore($record, ['verify_checksum' => false]);

        // The landlord connection ('sqlite', the app default here) never
        // carries the marker key FakeTenant::dbConfig() sets — its presence
        // proves the config really came from the tenant, not the landlord.
        $this->assertArrayHasKey('vanguard_test_marker', $capturedConfig);
        $this->assertSame('tenant-a', $capturedConfig['vanguard_test_marker']);
    }

    #[Test]
    public function restoring_the_landlord_never_enters_a_tenant_connection(): void
    {
        // Exists to prove it could leak in — must not be touched by a landlord restore.
        $this->makeTenant('tenant-a');

        $record = $this->makeRecord(['type' => 'landlord', 'tenant_id' => null]);

        $db = Mockery::mock(DatabaseDriver::class);
        $capturedConfig = null;
        $db->shouldReceive('restore')->once()->andReturnUsing(function ($driver, $config) use (&$capturedConfig) {
            $capturedConfig = $config;
        });

        $storage = Mockery::mock(StorageDriver::class);
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        $restoreService = new RestoreService($db, $storage, $store);
        $restoreService->restore($record, ['verify_checksum' => false]);

        $this->assertArrayNotHasKey('vanguard_test_marker', $capturedConfig);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. backupAllTenants() — each tenant gets its own record and archive name.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function backup_all_tenants_gives_each_tenant_its_own_record_and_archive_name(): void
    {
        $this->makeTenant('tenant-a');
        $this->makeTenant('tenant-b');

        config(['vanguard.queue.enabled' => false]);

        $db = Mockery::mock(DatabaseDriver::class);
        $capturedConfigs = [];
        $db->shouldReceive('dump')->twice()->andReturnUsing(function ($driver, $config, $dest) use (&$capturedConfigs) {
            $capturedConfigs[] = $config;

            return $dest;
        });

        $storage = Mockery::mock(StorageDriver::class);

        $capturedNames = [];
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_iso/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldReceive('bundle')->twice()->andReturnUsing(function ($files, $name) use (&$capturedNames) {
            $capturedNames[] = $name;

            return $this->bundleResult($name);
        });

        $manager = new BackupManager($db, $storage, $store, new FakeTenancyResolver);

        $results = $manager->backupAllTenants();

        $this->assertCount(2, $results);

        // Same manager instance served both tenants in one run — the run
        // where a shared/reused path or config would be easiest to leak.
        $this->assertNotSame($capturedNames[0], $capturedNames[1]);
        $this->assertNotSame($capturedConfigs[0]['vanguard_test_marker'], $capturedConfigs[1]['vanguard_test_marker']);

        $records = collect($results)->pluck('record');
        $this->assertNotSame($records[0]->tenant_id, $records[1]->tenant_id);
        $this->assertNotSame($records[0]->file_path, $records[1]->file_path);
        $this->assertSame(['tenant-a', 'tenant-b'], $records->pluck('tenant_id')->sort()->values()->all());
    }

    // ─────────────────────────────────────────────────────────────
    // 6. The tenancy context is always closed, including on exception.
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function run_for_tenant_ends_the_tenancy_context_even_when_the_callback_throws(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $resolver = new FakeTenancyResolver;

        $this->assertNull(FakeTenancyManager::instance()->activeTenant());

        try {
            $resolver->runForTenant($tenantA, function () {
                throw new RuntimeException('backup exploded mid-tenant');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('backup exploded mid-tenant', $e->getMessage());
        }

        $this->assertNull(FakeTenancyManager::instance()->activeTenant());
        $this->assertGreaterThanOrEqual(1, FakeTenancyManager::instance()->endCalls());
    }

    #[Test]
    public function restore_tenant_ends_the_tenancy_context_even_when_the_restore_throws(): void
    {
        $tenantA = $this->makeTenant('tenant-a');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenantA->getTenantKey()]);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('restore')->once()->andThrow(new RuntimeException('disk full mid-restore'));

        $storage = Mockery::mock(StorageDriver::class);
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        $restoreService = new RestoreService($db, $storage, $store);

        $this->expectException(RuntimeException::class);

        try {
            $restoreService->restore($record, ['verify_checksum' => false]);
        } finally {
            $this->assertNull(FakeTenancyManager::instance()->activeTenant());
            $this->assertGreaterThanOrEqual(1, FakeTenancyManager::instance()->endCalls());
        }
    }
}
