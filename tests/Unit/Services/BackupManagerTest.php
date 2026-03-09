<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\BackupStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class BackupManagerTest extends TestCase
{
    private MockInterface $db;
    private MockInterface $storage;
    private MockInterface $store;
    private MockInterface $tenancy;
    private BackupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->db      = Mockery::mock(DatabaseDriver::class);
        $this->storage = Mockery::mock(StorageDriver::class);
        $this->store   = Mockery::mock(BackupStorageManager::class);
        $this->tenancy = Mockery::mock(TenancyResolver::class);

        // Default clean-up stub
        $this->store->shouldReceive('cleanTmp')->byDefault()->andReturnNull();
        $this->store->shouldReceive('tmpPath')->byDefault()->andReturnUsing(
            fn ($f) => sys_get_temp_dir().'/vanguard_test/'.$f
        );

        $this->manager = new BackupManager(
            $this->db,
            $this->storage,
            $this->store,
            $this->tenancy,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // backupLandlord — happy path
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_landlord_creates_completed_record_on_success(): void
    {
        config(['vanguard.sources.landlord_database' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $this->tenancy->shouldReceive('landlordDbConfig')->once()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->once()->andReturn('/tmp/db.sql.gz');

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path'  => 'vanguard-backups/landlord_1.tar',
            'remote_path' => null,
            'size'        => 2048,
            'checksum'    => str_repeat('a', 64),
        ]);

        $record = $this->manager->backupLandlord();

        $this->assertInstanceOf(BackupRecord::class, $record);
        $this->assertSame('completed', $record->status);
        $this->assertSame('landlord', $record->type);
        $this->assertNull($record->tenant_id);
        $this->assertSame(2048, $record->file_size);
        $this->assertNotNull($record->completed_at);
    }

    /** @test */
    public function backup_landlord_fires_started_and_completed_events(): void
    {
        config(['vanguard.sources.landlord_database' => false]);
        config(['vanguard.sources.filesystem' => false]);

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'vanguard-backups/l.tar', 'remote_path' => null,
            'size' => 0, 'checksum' => str_repeat('b', 64),
        ]);

        $this->manager->backupLandlord();

        Event::assertDispatched(BackupStarted::class);
        Event::assertDispatched(BackupCompleted::class);
        Event::assertNotDispatched(BackupFailed::class);
    }

    /** @test */
    public function backup_landlord_marks_record_failed_and_fires_event_on_exception(): void
    {
        config(['vanguard.sources.landlord_database' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $this->tenancy->shouldReceive('landlordDbConfig')->once()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->once()->andThrow(new RuntimeException('disk full'));

        $this->expectException(RuntimeException::class);

        try {
            $this->manager->backupLandlord();
        } catch (RuntimeException $e) {
            $record = BackupRecord::latest()->first();

            $this->assertSame('failed', $record->status);
            $this->assertStringContainsString('disk full', $record->error);

            Event::assertDispatched(BackupStarted::class);
            Event::assertDispatched(BackupFailed::class);

            throw $e;
        }
    }

    /** @test */
    public function backup_landlord_always_cleans_tmp_even_on_failure(): void
    {
        config(['vanguard.sources.landlord_database' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $this->tenancy->shouldReceive('landlordDbConfig')->andReturn(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->shouldReceive('dump')->andThrow(new RuntimeException('boom'));
        $this->store->shouldReceive('cleanTmp')->once(); // must be called

        $this->expectException(RuntimeException::class);
        $this->manager->backupLandlord();
    }

    // ─────────────────────────────────────────────────────────────
    // backupLandlord — filesystem skipped when disabled
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_landlord_skips_db_when_source_disabled(): void
    {
        config(['vanguard.sources.landlord_database' => false]);
        config(['vanguard.sources.filesystem' => false]);

        $this->db->shouldNotReceive('dump');
        $this->storage->shouldNotReceive('archive');

        $this->store->shouldReceive('bundle')->once()->with([], Mockery::any())->andReturn([
            'local_path' => 'path', 'remote_path' => null, 'size' => 0, 'checksum' => str_repeat('c', 64),
        ]);

        $record = $this->manager->backupLandlord();

        $this->assertSame('completed', $record->status);
    }

    /** @test */
    public function backup_landlord_skips_filesystem_when_option_false(): void
    {
        config(['vanguard.sources.landlord_database' => false]);
        config(['vanguard.sources.filesystem' => true]);

        $this->storage->shouldNotReceive('archive');

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'path', 'remote_path' => null, 'size' => 0, 'checksum' => str_repeat('d', 64),
        ]);

        $record = $this->manager->backupLandlord(['include_filesystem' => false]);

        $this->assertSame('completed', $record->status);
    }

    // ─────────────────────────────────────────────────────────────
    // backupTenant
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_tenant_creates_completed_record_for_tenant(): void
    {
        config(['vanguard.sources.tenant_databases' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $tenant = $this->mockTenant('acme');

        $this->tenancy->shouldReceive('runForTenant')
            ->once()
            ->andReturnUsing(fn ($t, $cb) => $cb($t));

        $this->tenancy->shouldReceive('tenantDbConfig')->once()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->once()->andReturn('/tmp/tenant_db.sql.gz');

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path'  => 'vanguard-backups/tenant_acme.tar',
            'remote_path' => null,
            'size'        => 512,
            'checksum'    => str_repeat('e', 64),
        ]);

        $record = $this->manager->backupTenant($tenant);

        $this->assertSame('completed', $record->status);
        $this->assertSame('tenant', $record->type);
        $this->assertSame('acme', $record->tenant_id);
    }

    /** @test */
    public function backup_tenant_marks_record_failed_when_db_driver_throws(): void
    {
        config(['vanguard.sources.tenant_databases' => true]);

        $tenant = $this->mockTenant('broken_tenant');

        $this->tenancy->shouldReceive('runForTenant')
            ->once()
            ->andReturnUsing(fn ($t, $cb) => $cb($t));

        $this->tenancy->shouldReceive('tenantDbConfig')->once()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->once()->andThrow(new RuntimeException('Connection refused'));

        $this->expectException(RuntimeException::class);

        try {
            $this->manager->backupTenant($tenant);
        } finally {
            $record = BackupRecord::forTenant('broken_tenant')->latest()->first();

            $this->assertNotNull($record);
            $this->assertSame('failed', $record->status);
            $this->assertStringContainsString('Connection refused', $record->error);

            Event::assertDispatched(BackupFailed::class);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // backupFilesystem
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_filesystem_creates_filesystem_type_record(): void
    {
        $this->storage->shouldReceive('resolveBackupPaths')->once()->andReturn(['/storage/app']);
        $this->storage->shouldReceive('resolveExcludePaths')->once()->andReturn([]);
        $this->storage->shouldReceive('archive')->once()->andReturn('/tmp/fs.tar.gz');

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'vanguard-backups/fs.tar', 'remote_path' => null,
            'size' => 1024, 'checksum' => str_repeat('f', 64),
        ]);

        $record = $this->manager->backupFilesystem();

        $this->assertSame('filesystem', $record->type);
        $this->assertSame('completed', $record->status);
        $this->assertNull($record->tenant_id);
    }

    // ─────────────────────────────────────────────────────────────
    // backupAllTenants
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_all_tenants_runs_synchronously_when_queue_disabled(): void
    {
        config(['vanguard.queue.enabled' => false]);
        config(['vanguard.sources.tenant_databases' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $tenants = collect([
            $this->mockTenant('t1'),
            $this->mockTenant('t2'),
        ]);

        $this->tenancy->shouldReceive('allTenants')->once()->andReturn($tenants);

        $this->tenancy->shouldReceive('runForTenant')
            ->twice()
            ->andReturnUsing(fn ($t, $cb) => $cb($t));

        $this->tenancy->shouldReceive('tenantDbConfig')->twice()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->twice()->andReturn('/tmp/db.sql.gz');

        $this->store->shouldReceive('bundle')->twice()->andReturn([
            'local_path' => 'path', 'remote_path' => null, 'size' => 0, 'checksum' => str_repeat('g', 64),
        ]);

        $results = $this->manager->backupAllTenants();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('record', $results[0]);
        $this->assertArrayHasKey('record', $results[1]);
    }

    /** @test */
    public function backup_all_tenants_continues_when_one_tenant_fails(): void
    {
        config(['vanguard.queue.enabled' => false]);
        config(['vanguard.sources.tenant_databases' => true]);
        config(['vanguard.sources.filesystem' => false]);

        $tenants = collect([
            $this->mockTenant('ok_tenant'),
            $this->mockTenant('broken_tenant'),
        ]);

        $this->tenancy->shouldReceive('allTenants')->once()->andReturn($tenants);

        $this->tenancy->shouldReceive('runForTenant')
            ->twice()
            ->andReturnUsing(function ($t, $cb) {
                if ($t->getTenantKey() === 'broken_tenant') {
                    throw new RuntimeException('DB unreachable');
                }
                return $cb($t);
            });

        $this->tenancy->shouldReceive('tenantDbConfig')->once()->andReturn([
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->db->shouldReceive('dump')->once()->andReturn('/tmp/db.sql.gz');

        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'path', 'remote_path' => null, 'size' => 0, 'checksum' => str_repeat('h', 64),
        ]);

        $results = $this->manager->backupAllTenants();

        $this->assertCount(2, $results);

        $ok     = collect($results)->firstWhere('tenant', 'ok_tenant');
        $broken = collect($results)->firstWhere('tenant', 'broken_tenant');

        $this->assertArrayHasKey('record', $ok);
        $this->assertArrayHasKey('error', $broken);
        $this->assertStringContainsString('DB unreachable', $broken['error']);
    }

    // ─────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────

    private function mockTenant(string $id): object
    {
        $mock = Mockery::mock('Stancl\Tenancy\Contracts\Tenant');
        $mock->shouldReceive('getTenantKey')->andReturn($id);
        return $mock;
    }
}
