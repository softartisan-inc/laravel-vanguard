<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Tests\TestCase;

class BackupStorageManagerTest extends TestCase
{
    private BackupStorageManager $manager;
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tmpBase = sys_get_temp_dir().'/vanguard_bsm_tests_'.uniqid();
        config(['vanguard.tmp_path' => $this->tmpBase]);

        $this->manager = new BackupStorageManager;
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpBase));
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // tmpPath
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function tmp_path_returns_path_inside_session_directory(): void
    {
        $path = $this->manager->tmpPath('test_file.sql.gz');

        $this->assertStringContainsString('vanguard_', $path);
        $this->assertStringEndsWith('test_file.sql.gz', $path);
    }

    /** @test */
    public function tmp_path_directory_is_created_on_instantiation(): void
    {
        $path = $this->manager->tmpPath('some_file.txt');
        $dir  = dirname($path);

        $this->assertDirectoryExists($dir);
    }

    /** @test */
    public function clean_tmp_removes_session_directory(): void
    {
        $path = $this->manager->tmpPath('file.txt');
        file_put_contents($path, 'test');

        $dir = dirname($path);
        $this->assertDirectoryExists($dir);

        $this->manager->cleanTmp();

        $this->assertDirectoryDoesNotExist($dir);
    }

    // ─────────────────────────────────────────────────────────────
    // bundle
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function bundle_creates_tar_archive_and_stores_to_local_disk(): void
    {
        config(['vanguard.destinations.local.enabled' => true]);
        config(['vanguard.destinations.remote.enabled' => false]);

        // Create a real component file
        $dbDump = $this->manager->tmpPath('dump_db.sql.gz');
        file_put_contents($dbDump, gzencode('-- SQL dump'));

        $result = $this->manager->bundle(['database' => $dbDump], 'test_backup_001');

        $this->assertArrayHasKey('local_path', $result);
        $this->assertArrayHasKey('checksum', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertNotNull($result['local_path']);
        $this->assertNull($result['remote_path']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['checksum']); // SHA-256

        Storage::disk('local')->assertExists($result['local_path']);
    }

    /** @test */
    public function bundle_stores_to_both_local_and_remote_when_both_enabled(): void
    {
        Storage::fake('s3');

        config(['vanguard.destinations.local.enabled' => true]);
        config(['vanguard.destinations.remote.enabled' => true]);
        config(['vanguard.destinations.remote.disk' => 's3']);
        config(['vanguard.destinations.remote.path' => 'backups']);

        $dbDump = $this->manager->tmpPath('dump_db_remote.sql.gz');
        file_put_contents($dbDump, gzencode('-- SQL dump'));

        $result = $this->manager->bundle(['database' => $dbDump], 'test_remote_001');

        $this->assertNotNull($result['local_path']);
        $this->assertNotNull($result['remote_path']);

        Storage::disk('local')->assertExists($result['local_path']);
        Storage::disk('s3')->assertExists($result['remote_path']);
    }

    /** @test */
    public function bundle_throws_when_component_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager->bundle(['database' => '/nonexistent/file.sql.gz'], 'bad_backup');
    }

    // ─────────────────────────────────────────────────────────────
    // download
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function download_copies_file_from_local_disk_to_tmp(): void
    {
        config(['vanguard.destinations.local.disk' => 'local']);

        Storage::disk('local')->put('vanguard-backups/mybackup.tar', 'fake-tar-content');

        $tmpFile = $this->manager->download('vanguard-backups/mybackup.tar', false);

        $this->assertFileExists($tmpFile);
        $this->assertSame('fake-tar-content', file_get_contents($tmpFile));
    }

    /** @test */
    public function download_throws_when_file_not_found_on_disk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Backup file not found/');

        $this->manager->download('vanguard-backups/nonexistent.tar', false);
    }

    // ─────────────────────────────────────────────────────────────
    // verifyChecksum
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function verify_checksum_returns_true_for_valid_file(): void
    {
        $file = $this->manager->tmpPath('check.txt');
        file_put_contents($file, 'some content');

        $expected = hash_file('sha256', $file);

        $this->assertTrue($this->manager->verifyChecksum($file, $expected));
    }

    /** @test */
    public function verify_checksum_returns_false_for_corrupted_file(): void
    {
        $file = $this->manager->tmpPath('corrupted.txt');
        file_put_contents($file, 'original content');
        $expected = hash_file('sha256', $file);

        // Corrupt it
        file_put_contents($file, 'tampered content');

        $this->assertFalse($this->manager->verifyChecksum($file, $expected));
    }

    /** @test */
    public function verify_checksum_returns_false_for_wrong_hash(): void
    {
        $file = $this->manager->tmpPath('hash_test.txt');
        file_put_contents($file, 'real content');

        $this->assertFalse($this->manager->verifyChecksum($file, str_repeat('a', 64)));
    }

    // ─────────────────────────────────────────────────────────────
    // pruneOldBackups
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function prune_deletes_completed_records_beyond_retention(): void
    {
        config(['vanguard.retention.days' => 7]);

        $storedPath = 'vanguard-backups/old.tar';
        Storage::disk('local')->put($storedPath, 'data');

        $old = $this->makeRecord([
            'status'    => 'completed',
            'file_path' => $storedPath,
        ]);
        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subDays(10)]);

        $recent = $this->makeRecord(['status' => 'completed', 'file_path' => null]);

        $deleted = $this->manager->pruneOldBackups();

        $this->assertSame(1, $deleted);
        $this->assertNull(BackupRecord::find($old->id));
        $this->assertNotNull(BackupRecord::find($recent->id));
        Storage::disk('local')->assertMissing($storedPath);
    }

    /** @test */
    public function prune_does_not_delete_failed_records(): void
    {
        config(['vanguard.retention.days' => 1]);

        $failed = $this->makeRecord(['status' => 'failed']);
        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->where('id', $failed->id)
            ->update(['created_at' => now()->subDays(10)]);

        $deleted = $this->manager->pruneOldBackups();

        $this->assertSame(0, $deleted);
    }

    /** @test */
    public function prune_can_filter_by_tenant_id(): void
    {
        config(['vanguard.retention.days' => 1]);

        $acme   = $this->makeRecord(['status' => 'completed', 'tenant_id' => 'acme']);
        $globex = $this->makeRecord(['status' => 'completed', 'tenant_id' => 'globex']);

        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->whereIn('id', [$acme->id, $globex->id])
            ->update(['created_at' => now()->subDays(5)]);

        $deleted = $this->manager->pruneOldBackups('acme');

        $this->assertSame(1, $deleted);
        $this->assertNull(BackupRecord::find($acme->id));
        $this->assertNotNull(BackupRecord::find($globex->id));
    }

    // ─────────────────────────────────────────────────────────────
    // unBundle
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function unbundle_correctly_maps_database_and_storage_components(): void
    {
        // Create fake component files
        $dbFile      = $this->manager->tmpPath('landlord_1_db.sql.gz');
        $storageFile = $this->manager->tmpPath('landlord_1_storage.tar.gz');

        file_put_contents($dbFile, gzencode('-- SQL'));
        file_put_contents($storageFile, 'fake-tar');

        // Bundle them
        $result = $this->manager->bundle(
            ['database' => $dbFile, 'storage' => $storageFile],
            'landlord_1_test',
        );

        // Download & unbundle
        $bundlePath = $this->manager->download($result['local_path'], false);
        $components = $this->manager->unBundle($bundlePath);

        $this->assertArrayHasKey('database', $components);
        $this->assertArrayHasKey('storage', $components);
        $this->assertFileExists($components['database']);
        $this->assertFileExists($components['storage']);
    }
}
