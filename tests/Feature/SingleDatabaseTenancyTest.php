<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
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
use SoftArtisan\Vanguard\Vanguard;

require_once __DIR__.'/../Support/tenancy_shim.php';

/**
 * vanguard.tenancy.database_mode = 'single': one database shared by every
 * tenant (isolated by Row-Level Security, for instance), and a storage root
 * per tenant.
 *
 * There is no per-tenant database to dump there — no tenant connection
 * exists at all — so a tenant backup is its files, taken inside the tenancy
 * window where storage_path() points at the tenant's own root. The shared
 * database is backed up once, by the landlord backup.
 *
 * The default, 'multi', is the database-per-tenant behaviour every
 * installation had before the setting existed; the last tests here pin it.
 */
class SingleDatabaseTenancyTest extends TestCase
{
    private string $landlordStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Vanguard::auth(fn ($request) => true);

        Schema::create('vanguard_test_tenants', function ($table) {
            $table->string('id')->primary();
            $table->string('db_database');
            $table->string('storage_path');
        });

        config([
            'vanguard.tenancy.tenant_model' => FakeTenant::class,
            'vanguard.tenancy.enabled' => true,
            'vanguard.tenancy.database_mode' => 'single',
            'tenancy.database.tenant_connection_name' => 'tenant',
        ]);

        $this->landlordStoragePath = $this->app->storagePath();

        FakeTenancyManager::reset();
        FakeTenancyManager::instance()->boot('tenant', $this->landlordStoragePath);

        $this->app->instance(TenancyResolver::class, $this->resolverWithoutTenantConnection());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        FakeTenancyManager::reset();
        $this->app->useStoragePath($this->landlordStoragePath);
        parent::tearDown();
    }

    /**
     * A resolver on which reading the tenant connection is a test failure:
     * in single-database mode that connection does not exist.
     */
    private function resolverWithoutTenantConnection(): FakeTenancyResolver
    {
        return new class extends FakeTenancyResolver
        {
            public function tenantDbConfig(): array
            {
                throw new RuntimeException('tenantDbConfig() must not be called in single-database mode');
            }
        };
    }

    private function makeTenant(string $id): FakeTenant
    {
        $root = sys_get_temp_dir().'/vanguard_single_db_test/'.$id;
        @mkdir($root.'/app', 0777, true);

        return FakeTenant::create([
            'id' => $id,
            'db_database' => "/fake/{$id}.sqlite",
            'storage_path' => $root,
        ]);
    }

    /**
     * A BackupManager whose collaborators record what they were asked for.
     *
     * @param  array<string, mixed>  $seen
     */
    private function manager(array &$seen, ?TenancyResolver $resolver = null): BackupManager
    {
        $seen = ['dumps' => 0, 'archive_root' => null, 'bundled' => null];

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('dump')->andReturnUsing(function ($driver, $config, $dest) use (&$seen) {
            $seen['dumps']++;
            $seen['dump_config'] = $config;

            return $dest;
        });

        $storage = Mockery::mock(StorageDriver::class);
        $storage->shouldReceive('resolveBackupPaths')->andReturnUsing(fn () => [storage_path('app')]);
        $storage->shouldReceive('resolveExcludePaths')->andReturn([]);
        $storage->shouldReceive('archive')->andReturnUsing(function ($paths, $exclude, $destination) use (&$seen) {
            $seen['archive_root'] = rtrim(storage_path(), '/');

            return $destination;
        });

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_single/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldReceive('bundle')->andReturnUsing(function ($files, $name) use (&$seen) {
            $seen['bundled'] = $files;

            return [
                'local_path' => "vanguard-backups/{$name}.tar",
                'remote_path' => null,
                'ftp_path' => null,
                'size' => 10,
                'checksum' => hash('sha256', $name),
            ];
        });

        return new BackupManager($db, $storage, $store, $resolver ?? $this->resolverWithoutTenantConnection());
    }

    // ─── Backup ──────────────────────────────────────────────────

    #[Test]
    public function a_tenant_backup_holds_only_the_tenants_files_and_never_reads_a_tenant_connection(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];
        $record = $this->manager($seen)->backupTenant($tenant, ['include_filesystem' => true]);

        $this->assertSame('completed', $record->status, (string) $record->error);
        $this->assertSame(0, $seen['dumps'], 'there is no per-tenant database to dump');
        $this->assertSame(['storage'], array_keys($seen['bundled']));
        $this->assertStringEndsWith('_fs.tar.gz', $seen['bundled']['storage']);
        $this->assertSame(rtrim($tenant->storagePath(), '/'), $seen['archive_root'], 'archived inside the tenancy window');
    }

    #[Test]
    public function a_tenant_backup_includes_the_files_by_default(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];
        $record = $this->manager($seen)->backupTenant($tenant);

        $this->assertSame('completed', $record->status, (string) $record->error);
        $this->assertSame(['storage'], array_keys($seen['bundled']));
    }

    #[Test]
    public function the_record_says_the_backup_is_files_only(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];
        $record = $this->manager($seen)->backupTenant($tenant, ['include_filesystem' => true]);

        $this->assertSame(['filesystem'], $record->sources);
        $this->assertSame('single', $record->meta['database_mode'] ?? null);
    }

    #[Test]
    public function a_tenant_backup_without_files_is_refused(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];

        try {
            $this->manager($seen)->backupTenant($tenant, ['include_filesystem' => false]);
            $this->fail('A tenant backup without files has nothing to back up in single-database mode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing to back up for a tenant in single-database mode', $e->getMessage());
        }

        $this->assertNull($seen['bundled']);
        $this->assertSame(0, BackupRecord::count());
    }

    #[Test]
    public function a_tenant_backup_with_the_filesystem_source_disabled_is_refused(): void
    {
        config(['vanguard.sources.filesystem' => false]);
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing to back up for a tenant in single-database mode');

        $this->manager($seen)->backupTenant($tenant, ['include_filesystem' => true]);
    }

    #[Test]
    public function the_console_refuses_a_tenant_backup_with_no_filesystem(): void
    {
        $this->makeTenant('tenant-a');

        $this->artisan('vanguard:backup --tenant=tenant-a --no-filesystem')
            ->expectsOutputToContain('nothing to back up for a tenant in single-database mode')
            ->assertFailed();
    }

    #[Test]
    public function the_landlord_backup_still_dumps_the_shared_database(): void
    {
        $seen = [];
        $this->manager($seen)->backupLandlord(['include_filesystem' => false]);

        $this->assertSame(1, $seen['dumps']);
        $this->assertSame(['database'], array_keys($seen['bundled']));
    }

    #[Test]
    public function an_unknown_database_mode_is_refused_and_named(): void
    {
        config(['vanguard.tenancy.database_mode' => 'shared']);
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vanguard.tenancy.database_mode');

        $this->manager($seen)->backupTenant($tenant);
    }

    // ─── Restore ─────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $phases
     */
    private function restoreService(array &$phases, bool &$extracted): RestoreService
    {
        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('restore');

        $storage = Mockery::mock(StorageDriver::class);
        $storage->shouldReceive('isEmptyArchive')->andReturn(false);
        $storage->shouldReceive('extract')->andReturnUsing(function () use (&$extracted) {
            $extracted = true;
        });

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->andReturn(['storage' => '/tmp/fs.tar.gz']);
        $store->shouldReceive('cleanTmp');

        return new RestoreService($db, $storage, $store);
    }

    #[Test]
    public function a_tenant_restore_restores_files_and_never_touches_a_database(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $phases = [];
        $extracted = false;
        $this->restoreService($phases, $extracted)->restore($record, [
            'verify_checksum' => false,
            'restore_db' => true,
            'restore_storage' => true,
            'on_phase' => function (string $phase, array $context = []) use (&$phases) {
                $phases[] = $phase;
            },
        ]);

        $this->assertTrue($extracted);
        $this->assertNotContains('restoring database', $phases);
        $this->assertContains('skipping database', $phases, 'a requested database restore that cannot happen is said, not silently skipped');
    }

    #[Test]
    public function a_tenant_restore_into_another_database_is_refused(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $phases = [];
        $extracted = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('single-database mode');

        $this->restoreService($phases, $extracted)->restore($record, [
            'verify_checksum' => false,
            'restore_storage' => true,
            'database' => 'rehearsal',
        ]);
    }

    #[Test]
    public function the_console_says_a_tenant_restore_is_files_only_and_refuses_it_without_restore_storage(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey()]);

        $this->artisan("vanguard:restore {$record->id} --force")
            ->expectsOutputToContain('files only')
            ->expectsOutputToContain('restored from a landlord backup')
            ->assertFailed();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function restoreStorageNotRequested(): array
    {
        return [
            'restore_storage absent' => [[]],
            'restore_storage false' => [['restore_storage' => false]],
        ];
    }

    #[Test]
    #[DataProvider('restoreStorageNotRequested')]
    public function the_service_refuses_a_tenant_restore_that_would_restore_nothing(array $options): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldNotReceive('download');
        $service = new RestoreService(Mockery::mock(DatabaseDriver::class), Mockery::mock(StorageDriver::class), $store);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore_storage');

        $service->restore($record, array_merge(['verify_checksum' => false, 'restore_db' => true], $options));
    }

    #[Test]
    #[DataProvider('restoreStorageNotRequested')]
    public function the_api_refuses_a_tenant_restore_that_would_restore_nothing(array $options): void
    {
        Queue::fake();
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey()]);

        $this->postJson("/vanguard/api/backups/{$record->id}/restore", array_merge(['confirm' => 'tenant-a'], $options))
            ->assertStatus(400)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'restore_storage'));

        $this->assertSame(0, RestoreRecord::count(), 'nothing is queued for a restore that would restore nothing');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_api_queues_a_tenant_restore_of_the_files(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey()]);

        $this->postJson("/vanguard/api/backups/{$record->id}/restore", ['confirm' => 'tenant-a', 'restore_storage' => true])
            ->assertStatus(202);

        Queue::assertPushed(RunRestoreJob::class);
    }

    #[Test]
    public function the_console_refuses_database_for_a_tenant_restore_before_asking_anything(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey()]);

        $this->artisan("vanguard:restore {$record->id} --database=rehearsal --restore-storage")
            ->expectsOutputToContain('--database does not apply here')
            ->assertFailed();
    }

    // ─── Health ──────────────────────────────────────────────────

    #[Test]
    public function the_health_screen_does_not_check_a_tenant_connection_that_does_not_exist(): void
    {
        config([
            'database.connections.tenant' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'unused',
            ],
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $connections = collect($response->json('database_clients'))->pluck('connections')->flatten()->all();
        $this->assertNotContains('tenant', $connections);
    }

    // ─── Multi (default): unchanged ──────────────────────────────

    #[Test]
    public function in_multi_mode_a_tenant_backup_still_dumps_the_tenant_database(): void
    {
        config(['vanguard.tenancy.database_mode' => 'multi']);
        $tenant = $this->makeTenant('tenant-a');

        $seen = [];
        $record = $this->manager($seen, new FakeTenancyResolver)->backupTenant($tenant);

        $this->assertSame(1, $seen['dumps']);
        $this->assertSame($tenant->dbConfig(), $seen['dump_config']);
        $this->assertSame(['database'], array_keys($seen['bundled']), 'files stay opt-in for a tenant in multi mode');
        $this->assertSame(['landlord_database', 'tenant_databases', 'filesystem'], $record->sources);
        $this->assertArrayNotHasKey('database_mode', (array) $record->meta);
    }

    #[Test]
    public function without_the_setting_the_mode_is_multi(): void
    {
        config(['vanguard.tenancy.database_mode' => null]);

        $resolver = new FakeTenancyResolver;

        $this->assertSame('multi', $resolver->databaseMode());
        $this->assertFalse($resolver->sharesOneDatabase());
    }

    #[Test]
    public function in_multi_mode_the_console_refusal_and_files_only_wording_do_not_appear(): void
    {
        config(['vanguard.tenancy.database_mode' => 'multi']);
        $this->app->instance(TenancyResolver::class, new FakeTenancyResolver);

        $tenant = $this->makeTenant('tenant-a');
        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey()]);

        $this->artisan("vanguard:restore {$record->id}")
            ->doesntExpectOutputToContain('files only')
            ->expectsConfirmation('Do you want to proceed?', 'no')
            ->assertSuccessful();
    }
}
