<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\BackupStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class BackupRestoreIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::fake();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_integration_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        config(['vanguard.tmp_path' => $this->tmpDir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Full landlord backup → restore cycle
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function full_landlord_sqlite_backup_and_restore_cycle(): void
    {
        config([
            'vanguard.sources.landlord_database' => true,
            'vanguard.sources.filesystem'        => false,
            'vanguard.queue.enabled'             => false,
            'database.default'                   => 'sqlite',
            'database.connections.sqlite'        => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn(
            config('database.connections.sqlite') + ['driver' => 'sqlite']
        );

        $dbDriver  = new DatabaseDriver;
        $fsDriver  = new StorageDriver;
        $store     = new BackupStorageManager;
        $manager   = new BackupManager($dbDriver, $fsDriver, $store, $tenancy);

        // ── Backup ───────────────────────────────────────────────
        $record = $manager->backupLandlord();

        $this->assertSame('completed', $record->status);
        $this->assertNotNull($record->file_path);
        $this->assertNotNull($record->checksum);
        $this->assertGreaterThan(0, $record->file_size);

        Storage::disk('local')->assertExists($record->file_path);

        Event::assertDispatched(BackupStarted::class);
        Event::assertDispatched(BackupCompleted::class);

        // ── Restore ──────────────────────────────────────────────
        $store2   = new BackupStorageManager;
        $restore  = new RestoreService($dbDriver, $fsDriver, $store2);

        $result = $restore->restore($record, [
            'verify_checksum' => true,
            'restore_db'      => true,
            'restore_storage' => false,
        ]);

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Filesystem backup + restore cycle
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function filesystem_backup_and_restore_cycle(): void
    {
        // Create a test source directory
        $sourcePath = $this->tmpDir.'/app';
        mkdir($sourcePath);
        file_put_contents($sourcePath.'/important.txt', 'critical data');
        file_put_contents($sourcePath.'/config.json', json_encode(['env' => 'prod']));

        config([
            'vanguard.sources.filesystem_paths'   => [],
            'vanguard.sources.filesystem_exclude'  => [],
            'vanguard.sources.landlord_database'   => false,
            'vanguard.sources.filesystem'          => true,
        ]);

        $dbDriver = Mockery::mock(DatabaseDriver::class);
        $fsDriver = new StorageDriver;
        $store    = new BackupStorageManager;
        $tenancy  = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn([]);

        // Directly call archive with our test path
        $archivePath = $this->tmpDir.'/test_fs.tar.gz';
        $fsDriver->archive([$sourcePath], [], $archivePath);

        $this->assertFileExists($archivePath);

        // Restore to a new location
        $restorePath = $this->tmpDir.'/restored';
        $fsDriver->extract($archivePath, $restorePath);

        $this->assertDirectoryExists($restorePath);

        // Find files recursively
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($restorePath));
        $files = [];
        foreach ($iterator as $f) {
            if ($f->isFile()) {
                $files[$f->getFilename()] = file_get_contents($f->getPathname());
            }
        }

        $this->assertArrayHasKey('important.txt', $files);
        $this->assertSame('critical data', $files['important.txt']);
        $this->assertArrayHasKey('config.json', $files);
    }

    // ─────────────────────────────────────────────────────────────
    // Checksum integrity
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function restore_rejects_tampered_backup_file(): void
    {
        config([
            'vanguard.sources.landlord_database' => false,
            'vanguard.sources.filesystem'        => false,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn([]);

        $dbDriver = new DatabaseDriver;
        $fsDriver = new StorageDriver;
        $store    = new BackupStorageManager;
        $manager  = new BackupManager($dbDriver, $fsDriver, $store, $tenancy);

        $record = $manager->backupLandlord();

        // Tamper the stored file
        $diskPath = Storage::disk('local')->path($record->file_path);
        file_put_contents($diskPath, 'TAMPERED CONTENT');

        $store2  = new BackupStorageManager;
        $restore = new RestoreService($dbDriver, $fsDriver, $store2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Checksum mismatch/');

        $restore->restore($record, ['verify_checksum' => true]);
    }

    // ─────────────────────────────────────────────────────────────
    // BackupRecord persistence
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backup_record_is_persisted_with_all_fields(): void
    {
        config([
            'vanguard.sources.landlord_database' => false,
            'vanguard.sources.filesystem'        => false,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn([]);

        $manager = new BackupManager(
            new DatabaseDriver,
            new StorageDriver,
            new BackupStorageManager,
            $tenancy,
        );

        $record = $manager->backupLandlord(['tag' => 'nightly']);

        $fresh = BackupRecord::find($record->id);

        $this->assertNotNull($fresh);
        $this->assertSame('landlord', $fresh->type);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->file_path);
        $this->assertNotNull($fresh->checksum);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->completed_at);
        $this->assertIsArray($fresh->meta);
        $this->assertSame('nightly', $fresh->meta['tag']);
    }

    /** @test */
    public function backup_failure_persists_error_message(): void
    {
        config([
            'vanguard.sources.landlord_database' => true,
            'vanguard.sources.filesystem'        => false,
        ]);

        $dbDriver = Mockery::mock(DatabaseDriver::class);
        $dbDriver->shouldReceive('dump')->andThrow(new \RuntimeException('Connection timeout'));

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn(['driver' => 'sqlite', 'database' => ':memory:']);

        $manager = new BackupManager(
            $dbDriver,
            new StorageDriver,
            new BackupStorageManager,
            $tenancy,
        );

        try {
            $manager->backupLandlord();
        } catch (\RuntimeException) {}

        $record = BackupRecord::latest()->first();

        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('Connection timeout', $record->error);
        $this->assertNotNull($record->completed_at);

        Event::assertDispatched(BackupFailed::class, fn ($e) => str_contains($e->exception->getMessage(), 'Connection timeout'));
    }

    // ─────────────────────────────────────────────────────────────
    // Prune integration
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function prune_removes_old_backup_files_from_storage(): void
    {
        config(['vanguard.retention.days' => 3]);

        Storage::disk('local')->put('vanguard-backups/old.tar', 'data');

        $old = $this->makeRecord([
            'status'    => 'completed',
            'file_path' => 'vanguard-backups/old.tar',
        ]);
        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subDays(5)]);

        $store   = new BackupStorageManager;
        $deleted = $store->pruneOldBackups();

        $this->assertSame(1, $deleted);
        $this->assertNull(BackupRecord::find($old->id));
        Storage::disk('local')->assertMissing('vanguard-backups/old.tar');
    }
}
