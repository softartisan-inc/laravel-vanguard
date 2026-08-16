<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * File handles opened to stream an archive to a destination.
 *
 * They were closed on the line after the upload, which is the one line that
 * does not run when the upload throws. On a long-lived Horizon worker running
 * a backup an hour, the leaked descriptors accumulate until the process cannot
 * open a file at all.
 */
class BackupStorageManagerStreamTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function store(): object
    {
        return new class extends BackupStorageManager
        {
            public function put(string $disk, string $storagePath, string $sourcePath): bool
            {
                return $this->putStream($disk, $storagePath, $sourcePath);
            }
        };
    }

    /**
     * How many file descriptors this process currently holds.
     */
    protected function openDescriptors(): int
    {
        return count(glob('/proc/self/fd/*') ?: []);
    }

    protected function sourceFile(): string
    {
        $path = sys_get_temp_dir().'/vanguard_stream_source_'.bin2hex(random_bytes(4)).'.tar';

        file_put_contents($path, 'ARCHIVE-BYTES');

        return $path;
    }

    #[Test]
    public function it_streams_the_file_to_the_disk_and_closes_the_handle(): void
    {
        Storage::fake('local');

        $source = $this->sourceFile();
        $before = $this->openDescriptors();

        $this->assertTrue($this->store()->put('local', 'vanguard-backups/ok.tar', $source));

        $this->assertSame('ARCHIVE-BYTES', Storage::disk('local')->get('vanguard-backups/ok.tar'));
        $this->assertSame($before, $this->openDescriptors());

        @unlink($source);
    }

    #[Test]
    public function it_closes_the_handle_when_the_destination_throws(): void
    {
        // A destination that cannot be written — the case the old code could
        // not survive, because the fclose() sat after the put(). The root is a
        // path under a non-directory, so mkdir fails on any POSIX system, and
        // 'throw' makes Flysystem raise rather than answer false.
        config(['filesystems.disks.unwritable' => [
            'driver' => 'local',
            'root' => '/dev/null/vanguard-cannot-write',
            'throw' => true,
        ]]);

        $source = $this->sourceFile();
        $before = $this->openDescriptors();

        try {
            $this->store()->put('unwritable', 'vanguard-backups/nope.tar', $source);
            $this->fail('the failure must reach the caller');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            $before,
            $this->openDescriptors(),
            'a refused upload must not cost the worker a file descriptor',
        );

        @unlink($source);
    }

    #[Test]
    public function it_reports_a_refused_write_rather_than_claiming_success(): void
    {
        // A disk that answers false instead of raising — how the S3 adapter
        // reports a refused PUT when exceptions are turned off. The bundle
        // then has to raise its own error rather than record a destination it
        // never reached.
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('refusing')->andReturn($disk);

        $source = $this->sourceFile();
        $before = $this->openDescriptors();

        $this->assertFalse($this->store()->put('refusing', 'vanguard-backups/nope.tar', $source));
        $this->assertSame($before, $this->openDescriptors());

        @unlink($source);
    }

    #[Test]
    public function a_refused_local_write_fails_the_backup_instead_of_recording_a_copy(): void
    {
        // The cross-filesystem case: tmp on tmpfs and storage on a mounted
        // volume, which is the normal Docker layout. rename() answers EXDEV
        // there, so the stream fallback runs — and it was the one branch whose
        // false was dropped, leaving a record that named a local copy nothing
        // had ever written. The next download or restore answers 404 on it.
        config([
            'vanguard.destinations.local.enabled' => true,
            'vanguard.destinations.local.disk' => 'refusing',
            'vanguard.destinations.remote.enabled' => false,
            'vanguard.destinations.ftp.enabled' => false,
            // Any driver but 'local', so persistToLocalDisk() skips the
            // rename() fast path and streams the way EXDEV forces it to.
            'filesystems.disks.refusing' => ['driver' => 'sftp'],
        ]);

        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('refusing')->andReturn($disk);

        $store = app(BackupStorageManager::class);
        $thrown = null;

        try {
            $store->bundle([], 'refused-backup');
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $store->cleanTmp();
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'a destination that refused the write must fail the backup, not be recorded as reached',
        );
        $this->assertStringContainsString('refusing', $thrown->getMessage());
    }

    #[Test]
    public function a_download_leaves_no_handle_open_behind_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('vanguard-backups/archive.tar', 'ARCHIVE-BYTES');

        $store = app(BackupStorageManager::class);

        // The first call creates the session tmp directory; measure after it
        // so the directory handle is not counted as a leak.
        $store->download('vanguard-backups/archive.tar', 'local');
        $before = $this->openDescriptors();

        $path = $store->download('vanguard-backups/archive.tar', 'local');

        $this->assertSame('ARCHIVE-BYTES', file_get_contents($path));
        $this->assertSame($before, $this->openDescriptors());

        $store->cleanTmp();
    }
}
