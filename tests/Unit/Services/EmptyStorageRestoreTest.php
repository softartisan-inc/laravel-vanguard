<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The mirror of an empty backup: restoring a filesystem member that holds no
 * file put nothing back and answered "Restore completed successfully". An
 * operator restoring an archive taken before the emptiness was noticed has to
 * be told, or they will believe their files came back.
 */
class EmptyStorageRestoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_empty_restore_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function the_driver_recognises_an_archive_holding_no_member(): void
    {
        $driver = new StorageDriver;

        $empty = $driver->archive([], [], $this->tmpDir.'/empty.tar.gz');

        $source = $this->tmpDir.'/source';
        mkdir($source);
        file_put_contents($source.'/hello.txt', 'hello');
        $full = $driver->archive([$source], [], $this->tmpDir.'/full.tar.gz');

        $this->assertTrue($driver->isEmptyArchive($empty));
        $this->assertFalse($driver->isEmptyArchive($full));
    }

    #[Test]
    public function a_corrupt_archive_is_not_reported_as_empty(): void
    {
        // Unreadable is not the same claim as empty, and the extraction that
        // follows reports the real error with its own message.
        $broken = $this->tmpDir.'/broken.tar.gz';
        file_put_contents($broken, 'this is not a gzip stream');

        $this->assertFalse((new StorageDriver)->isEmptyArchive($broken));
    }

    #[Test]
    public function restoring_a_storage_member_with_no_file_says_so(): void
    {
        Log::spy();

        $seen = [];

        $this->restoreStorage(empty: true, onPhase: function (string $phase, array $context = []) use (&$seen) {
            $seen[] = [$phase, $context];
        });

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => str_contains($message, 'holds no file')
        );

        $this->assertContains(['restoring files', ['empty' => true]], $seen);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function restoring_a_storage_member_that_holds_files_says_nothing(): void
    {
        Log::spy();

        $seen = [];

        $this->restoreStorage(empty: false, onPhase: function (string $phase, array $context = []) use (&$seen) {
            $seen[] = [$phase, $context];
        });

        Log::shouldNotHaveReceived('warning');

        $this->assertContains(['restoring files', ['empty' => false]], $seen);

        $this->addToAssertionCount(1);
    }

    /**
     * Run a filesystem-only restore whose storage member is empty or not.
     */
    private function restoreStorage(bool $empty, callable $onPhase): void
    {
        $storage = Mockery::mock(StorageDriver::class);
        $storage->shouldReceive('isEmptyArchive')->once()->andReturn($empty);
        $storage->shouldReceive('extract')->once();

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['storage' => '/tmp/fs.tar.gz']);
        $store->shouldReceive('cleanTmp')->andReturnNull();

        $record = $this->makeRecord([
            'type' => 'filesystem',
            'file_path' => 'vanguard-backups/fs.tar',
            'checksum' => null,
        ]);

        (new RestoreService(Mockery::mock(DatabaseDriver::class), $storage, $store))
            ->restore($record, [
                'verify_checksum' => false,
                'restore_storage' => true,
                'on_phase' => $onPhase,
            ]);
    }
}
