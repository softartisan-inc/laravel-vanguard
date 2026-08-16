<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A record that already reached a final status must not be written again.
 *
 * With --queue dispatching for real, a job that times out is retried while the
 * first attempt is still finishing: two workers then hold the same record and
 * the slower one had the last word, flipping a completed backup to failed.
 */
class BackupManagerRecordGuardTest extends TestCase
{
    protected function manager(): object
    {
        return new class(app(DatabaseDriver::class), app(StorageDriver::class), app(BackupStorageManager::class), app(TenancyResolver::class)) extends BackupManager
        {
            public function complete(BackupRecord $record, array $bundle): void
            {
                $this->completeRecord($record, $bundle);
            }

            public function fail(BackupRecord $record, \Throwable $e): void
            {
                $this->failRecord($record, $e);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function bundle(array $overrides = []): array
    {
        return array_merge([
            'local_path' => 'vanguard-backups/first.tar.gz',
            'remote_path' => null,
            'ftp_path' => null,
            'size' => 2048,
            'checksum' => hash('sha256', 'first'),
        ], $overrides);
    }

    #[Test]
    public function it_completes_a_running_backup(): void
    {
        $record = $this->makeRecord(['status' => 'running', 'file_path' => null, 'completed_at' => null]);

        $this->manager()->complete($record, $this->bundle());

        $record->refresh();

        $this->assertSame('completed', $record->status);
        $this->assertSame('vanguard-backups/first.tar.gz', $record->file_path);
        $this->assertSame(2048, $record->file_size);
        $this->assertNotNull($record->completed_at);
    }

    #[Test]
    public function it_fails_a_running_backup_with_the_exact_error(): void
    {
        $record = $this->makeRecord(['status' => 'running', 'completed_at' => null]);

        $this->manager()->fail($record, new \RuntimeException("mysqldump: unknown option '--x'"));

        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertSame("mysqldump: unknown option '--x'", $record->error);
    }

    #[Test]
    public function a_retried_job_cannot_flip_a_completed_backup_to_failed(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'error' => null]);

        $this->manager()->fail($record, new \RuntimeException('the retry timed out'));

        $record->refresh();

        $this->assertSame('completed', $record->status, 'a finished backup stays finished');
        $this->assertNull($record->error);
    }

    #[Test]
    public function a_retried_job_cannot_dress_a_failed_backup_up_as_a_success(): void
    {
        $record = $this->makeRecord([
            'status' => 'failed',
            'error' => 'no space left on device',
            'file_path' => null,
        ]);

        $this->manager()->complete($record, $this->bundle());

        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertSame('no space left on device', $record->error);
        $this->assertNull($record->file_path, 'nothing may be written over a final status');
    }

    #[Test]
    public function the_guard_reads_the_row_rather_than_the_copy_the_worker_is_holding(): void
    {
        // The real shape of the race: worker A holds a model loaded when the
        // record was still running, worker B finishes and writes 'completed'
        // to the row. A's in-memory copy still says 'running', so a guard that
        // trusted the attribute in hand would wave the overwrite straight
        // through.
        $record = $this->makeRecord(['status' => 'running', 'error' => null]);

        BackupRecord::whereKey($record->id)->update(['status' => 'completed']);

        $this->assertSame('running', $record->status, 'the stale copy the worker holds');

        $this->manager()->fail($record, new \RuntimeException('the retry timed out'));

        $this->assertSame('completed', BackupRecord::findOrFail($record->id)->status);
    }
}
