<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

class RestoreWipeStorageTest extends TestCase
{
    private string $tmpDir;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir     = sys_get_temp_dir().'/vanguard_wipe_'.uniqid();
        $this->storageDir = $this->tmpDir.'/storage';

        mkdir($this->storageDir.'/app', 0755, true);
        mkdir($this->storageDir.'/logs', 0755, true);
        mkdir($this->storageDir.'/framework/cache', 0755, true);

        // Point the whole application at a throwaway storage tree, so the
        // restore really moves files around without touching the test host.
        $this->app->useStoragePath($this->storageDir);

        config([
            'vanguard.tmp_path'                 => $this->tmpDir.'/tmp',
            'vanguard.sources.filesystem_paths' => ['app'],
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Default behaviour — merge
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_filesystem_restore_merges_by_default_and_keeps_files_created_after_the_backup(): void
    {
        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/app/created-after-backup.txt', 'newer than the backup');
        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
        ]);

        $this->assertFileExists($this->storageDir.'/app/created-after-backup.txt');
        $this->assertFileExists($this->storageDir.'/logs/laravel.log');
    }

    // ─────────────────────────────────────────────────────────────
    // wipe_storage — replace instead of merge
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function wipe_storage_empties_the_backed_up_directory_before_extracting(): void
    {
        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/app/created-after-backup.txt', 'newer than the backup');

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
            'wipe_storage'    => true,
        ]);

        // The file that did not exist at backup time is gone…
        $this->assertFileDoesNotExist($this->storageDir.'/app/created-after-backup.txt');
        // …while the directory itself survives, and the bundle was extracted.
        $this->assertDirectoryExists($this->storageDir.'/app');
        $this->assertContains('from-backup.txt', $this->filenamesUnder($this->storageDir));
    }

    #[Test]
    public function wipe_storage_leaves_paths_outside_the_backed_up_scope_untouched(): void
    {
        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');
        file_put_contents($this->storageDir.'/framework/cache/session', 'session data');

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
            'wipe_storage'    => true,
        ]);

        $this->assertFileExists($this->storageDir.'/logs/laravel.log');
        $this->assertSame('log line', file_get_contents($this->storageDir.'/logs/laravel.log'));
        $this->assertFileExists($this->storageDir.'/framework/cache/session');
    }

    #[Test]
    public function wipe_storage_never_erases_the_whole_storage_path(): void
    {
        // A stray entry resolving to storage_path() itself must not turn the
        // restore into a full wipe of logs, sessions and framework caches.
        config(['vanguard.sources.filesystem_paths' => ['', '.', '../storage']]);

        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/app/created-after-backup.txt', 'newer than the backup');
        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
            'wipe_storage'    => true,
        ]);

        $this->assertFileExists($this->storageDir.'/logs/laravel.log');
        $this->assertFileExists($this->storageDir.'/app/created-after-backup.txt');
    }

    #[Test]
    public function wipe_storage_also_applies_to_a_landlord_restore(): void
    {
        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/app/created-after-backup.txt', 'newer than the backup');
        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
            'wipe_storage'    => true,
        ]);

        $this->assertFileDoesNotExist($this->storageDir.'/app/created-after-backup.txt');
        $this->assertFileExists($this->storageDir.'/logs/laravel.log');
    }

    #[Test]
    public function wipe_storage_is_ignored_when_the_filesystem_is_not_restored(): void
    {
        $archive = $this->archiveCurrentStorageApp();

        file_put_contents($this->storageDir.'/app/created-after-backup.txt', 'newer than the backup');

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => false,
            'wipe_storage'    => true,
        ]);

        $this->assertFileExists($this->storageDir.'/app/created-after-backup.txt');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Archive the current content of storage/app the way a real backup does.
     */
    private function archiveCurrentStorageApp(): string
    {
        file_put_contents($this->storageDir.'/app/from-backup.txt', 'backed up content');

        $archive = $this->tmpDir.'/storage.tar.gz';

        (new StorageDriver)->archive([$this->storageDir.'/app'], [], $archive);

        return $archive;
    }

    /**
     * Build a RestoreService whose bundle always yields the given storage archive.
     */
    private function restoreService(string $archive): RestoreService
    {
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->andReturn($this->tmpDir.'/bundle.tar');
        $store->shouldReceive('unBundle')->andReturn(['storage' => $archive]);
        $store->shouldReceive('cleanTmp')->andReturnNull();

        return new RestoreService(
            Mockery::mock(DatabaseDriver::class),
            new StorageDriver,
            $store,
        );
    }

    /**
     * Every filename found anywhere under the given directory.
     *
     * @return array<string>
     */
    private function filenamesUnder(string $directory): array
    {
        $names    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $names[] = $file->getFilename();
            }
        }

        return $names;
    }
}
