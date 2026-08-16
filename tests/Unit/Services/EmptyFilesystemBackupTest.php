<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A backup asked for the filesystem whose configured paths resolve to nothing
 * produced an empty tarball and reported success — observed on a real
 * preprod tenant whose storage root had no 'app' directory. An archive that
 * looks healthy, weighs almost nothing and restores nothing is the exact
 * failure this package exists to abolish, so it may not pass in silence.
 */
class EmptyFilesystemBackupTest extends TestCase
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

        $this->db = Mockery::mock(DatabaseDriver::class);
        $this->storage = Mockery::mock(StorageDriver::class);
        $this->store = Mockery::mock(BackupStorageManager::class);
        $this->tenancy = Mockery::mock(TenancyResolver::class);

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

    #[Test]
    public function a_landlord_backup_that_resolved_no_path_is_warned_about_and_marked(): void
    {
        Log::spy();

        config([
            'vanguard.sources.landlord_database' => false,
            'vanguard.sources.filesystem' => true,
            'vanguard.sources.filesystem_paths' => ['app'],
        ]);

        $this->storage->shouldReceive('resolveBackupPaths')->once()->andReturn([]);
        $this->storage->shouldReceive('resolveExcludePaths')->once()->andReturn([]);
        $this->storage->shouldReceive('archive')->once()->andReturn('/tmp/fs.tar.gz');
        $this->bundleReturns();

        $record = $this->manager->backupLandlord();

        $this->assertSame('completed', $record->status);
        $this->assertTrue($record->meta['filesystem_empty'] ?? false);
        $this->assertSame(['app'], $record->meta['filesystem_paths'] ?? null);
        $this->assertSame(
            rtrim(storage_path(), DIRECTORY_SEPARATOR),
            $record->meta['storage_root'] ?? null,
        );

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'no existing path')
                && ($context['configured_paths'] ?? null) === ['app']
                && ($context['storage_root'] ?? null) === rtrim(storage_path(), DIRECTORY_SEPARATOR)
                && ($context['target'] ?? null) === 'landlord'
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_filesystem_backup_that_did_resolve_paths_is_not_warned_about(): void
    {
        Log::spy();

        $this->storage->shouldReceive('resolveBackupPaths')->once()->andReturn([storage_path('app')]);
        $this->storage->shouldReceive('resolveExcludePaths')->once()->andReturn([]);
        $this->storage->shouldReceive('archive')->once()->andReturn('/tmp/fs.tar.gz');
        $this->bundleReturns();

        $record = $this->manager->backupFilesystem();

        $this->assertSame('completed', $record->status);
        $this->assertFalse($record->meta['filesystem_empty'] ?? false);

        Log::shouldNotHaveReceived('warning');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_tenant_backup_that_resolved_no_path_names_the_tenant(): void
    {
        Log::spy();

        config([
            'vanguard.sources.tenant_databases' => false,
            'vanguard.sources.filesystem' => true,
            'vanguard.sources.filesystem_paths' => ['app', 'app/public'],
        ]);

        $tenant = Mockery::mock('Stancl\Tenancy\Contracts\Tenant');
        $tenant->shouldReceive('getTenantKey')->andReturn('9');

        $this->tenancy->shouldReceive('runForTenant')->once()->andReturnUsing(fn ($t, $cb) => $cb($t));
        $this->storage->shouldReceive('resolveBackupPaths')->once()->andReturn([]);
        $this->storage->shouldReceive('resolveExcludePaths')->once()->andReturn([]);
        $this->storage->shouldReceive('archive')->once()->andReturn('/tmp/fs.tar.gz');
        $this->bundleReturns();

        $record = $this->manager->backupTenant($tenant, ['include_filesystem' => true]);

        $this->assertSame('completed', $record->status);
        $this->assertTrue($record->meta['filesystem_empty'] ?? false);
        $this->assertSame(['app', 'app/public'], $record->meta['filesystem_paths'] ?? null);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'no existing path')
                && ($context['target'] ?? null) === 'tenant [9]'
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_backup_that_was_never_asked_for_the_filesystem_is_not_marked(): void
    {
        Log::spy();

        config([
            'vanguard.sources.landlord_database' => false,
            'vanguard.sources.filesystem' => true,
        ]);

        $this->storage->shouldNotReceive('resolveBackupPaths');
        $this->bundleReturns();

        $record = $this->manager->backupLandlord(['include_filesystem' => false]);

        $this->assertSame('completed', $record->status);
        $this->assertFalse($record->meta['filesystem_empty'] ?? false);

        Log::shouldNotHaveReceived('warning');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_default_is_to_warn_and_never_to_fail_the_backup(): void
    {
        // A landlord installation that genuinely keeps nothing under
        // storage/app is legitimate; turning it into a failing backup on
        // upgrade would be worse than the silence being fixed here. Read off
        // the shipped config file, which is what an installation publishes.
        $shipped = require __DIR__.'/../../../config/vanguard.php';

        $this->assertSame('warn', $shipped['sources']['on_empty_filesystem']);
    }

    #[Test]
    public function an_installation_can_ask_for_an_empty_filesystem_to_fail_the_backup(): void
    {
        config([
            'vanguard.sources.landlord_database' => false,
            'vanguard.sources.filesystem' => true,
            'vanguard.sources.on_empty_filesystem' => 'fail',
        ]);

        $this->storage->shouldReceive('resolveBackupPaths')->once()->andReturn([]);
        $this->storage->shouldNotReceive('archive');

        try {
            $this->manager->backupLandlord();
            $this->fail('The backup should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no existing path', $e->getMessage());
        }

        $record = BackupRecord::latest()->first();

        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('no existing path', $record->error);
    }

    /**
     * Stub the bundling step with a completed-looking result.
     */
    private function bundleReturns(): void
    {
        $this->store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'vanguard-backups/test.tar',
            'remote_path' => null,
            'ftp_path' => null,
            'size' => 1024,
            'checksum' => str_repeat('a', 64),
        ]);
    }
}
