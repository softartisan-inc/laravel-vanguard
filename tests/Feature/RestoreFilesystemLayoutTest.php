<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A restore is only a restore if the files come back at the very path they were
 * taken from. These assertions are deliberately exact — "the file exists
 * somewhere under storage" is what let a whole absolute tree be recreated
 * inside storage/ without anyone noticing.
 */
class RestoreFilesystemLayoutTest extends TestCase
{
    private string $tmpDir;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir     = sys_get_temp_dir().'/vanguard_restore_layout_'.uniqid();
        $this->storageDir = $this->tmpDir.'/storage';

        mkdir($this->storageDir.'/app/public', 0755, true);
        mkdir($this->storageDir.'/logs', 0755, true);

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

    #[Test]
    public function a_filesystem_restore_puts_each_file_back_at_its_original_path(): void
    {
        file_put_contents($this->storageDir.'/app/invoice.pdf', 'invoice bytes');
        file_put_contents($this->storageDir.'/app/public/logo.png', 'logo bytes');

        $archive = $this->tmpDir.'/storage.tar.gz';
        (new StorageDriver)->archive([$this->storageDir.'/app'], [], $archive);

        exec('rm -rf '.escapeshellarg($this->storageDir.'/app'));

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
        ]);

        $this->assertSame('invoice bytes', @file_get_contents($this->storageDir.'/app/invoice.pdf'));
        $this->assertSame('logo bytes', @file_get_contents($this->storageDir.'/app/public/logo.png'));
    }

    #[Test]
    public function a_restore_never_recreates_an_absolute_tree_inside_the_storage_path(): void
    {
        file_put_contents($this->storageDir.'/app/invoice.pdf', 'invoice bytes');

        $archive = $this->tmpDir.'/storage.tar.gz';
        (new StorageDriver)->archive([$this->storageDir.'/app'], [], $archive);

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
        ]);

        $topLevel = array_values(array_diff(scandir($this->storageDir) ?: [], ['.', '..']));
        sort($topLevel);

        $this->assertSame(['app', 'logs'], $topLevel);
    }

    #[Test]
    public function a_bundle_written_before_the_layout_fix_still_restores_in_place(): void
    {
        // Built the way the pre-fix code did it: absolute paths handed to tar,
        // which stores the producing machine's whole path as the member name.
        $legacyStorage = $this->tmpDir.'/legacy/var/www/html/storage';
        mkdir($legacyStorage.'/app/public', 0755, true);
        file_put_contents($legacyStorage.'/app/invoice.pdf', 'invoice bytes');
        file_put_contents($legacyStorage.'/app/public/logo.png', 'logo bytes');

        $archive = $this->tmpDir.'/legacy.tar.gz';
        exec(sprintf(
            'tar czf %s %s 2>&1',
            escapeshellarg($archive),
            escapeshellarg($legacyStorage.'/app'),
        ));

        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $record = $this->makeRecord(['type' => 'filesystem', 'checksum' => null]);

        $this->restoreService($archive)->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
        ]);

        $this->assertSame('invoice bytes', @file_get_contents($this->storageDir.'/app/invoice.pdf'));
        $this->assertSame('logo bytes', @file_get_contents($this->storageDir.'/app/public/logo.png'));
        $this->assertSame('log line', @file_get_contents($this->storageDir.'/logs/laravel.log'));

        $topLevel = array_values(array_diff(scandir($this->storageDir) ?: [], ['.', '..']));
        sort($topLevel);

        $this->assertSame(['app', 'logs'], $topLevel);
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
}
