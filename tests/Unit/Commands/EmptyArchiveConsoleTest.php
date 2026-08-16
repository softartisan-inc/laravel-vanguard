<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The console half of the empty-archive problem. A log line nobody reads is
 * not a warning: the operator watching a backup run, and the operator
 * restoring one, both have to be told on their screen that the archive
 * carries no file.
 */
class EmptyArchiveConsoleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function the_backup_command_warns_when_the_archive_carries_no_file(): void
    {
        $this->landlordBackupReturns([
            'meta' => [
                'filesystem_empty' => true,
                'filesystem_paths' => ['app'],
                'storage_root' => '/var/www/html/storage/tenant9',
            ],
        ]);

        $output = $this->runAndCaptureLandlordBackup();

        $this->assertStringContainsString('no file', $output);
        $this->assertStringContainsString('/var/www/html/storage/tenant9', $output);
        $this->assertStringContainsString('app', $output);
        $this->assertStringContainsString('filesystem_paths', $output);
    }

    #[Test]
    public function the_backup_command_says_nothing_when_the_archive_holds_files(): void
    {
        $this->landlordBackupReturns([]);

        $output = $this->runAndCaptureLandlordBackup();

        $this->assertStringNotContainsString('carries no file', $output);
    }

    #[Test]
    public function the_restore_command_warns_when_the_filesystem_member_holds_no_file(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnUsing(function ($record, $options) {
            $options['on_phase']('restoring files', ['empty' => true]);

            return true;
        });

        $this->app->instance(RestoreService::class, $service);

        Artisan::call("vanguard:restore {$record->id} --restore-storage --force");

        $output = Artisan::output();

        $this->assertStringContainsString('holds no file', $output);
        $this->assertStringContainsString('Restore completed', $output);
    }

    #[Test]
    public function the_restore_command_says_that_wipe_storage_was_refused_on_an_empty_member(): void
    {
        // Erasing the live directories to replace them with an empty archive
        // is not a restore; the service refuses it, and the console has to say
        // so or the operator reads the refusal as a wipe that happened.
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnUsing(function ($record, $options) {
            $options['on_phase']('restoring files', ['empty' => true]);

            return true;
        });

        $this->app->instance(RestoreService::class, $service);

        Artisan::call("vanguard:restore {$record->id} --restore-storage --wipe-storage --force");

        $this->assertStringContainsString('--wipe-storage was refused', Artisan::output());
    }

    /**
     * Bind a BackupManager whose landlord backup returns a crafted record.
     */
    private function landlordBackupReturns(array $attributes): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')->once()->andReturn($this->makeRecord($attributes));

        $this->app->instance(BackupManager::class, $manager);
    }

    /**
     * Run the landlord backup and return everything it printed.
     */
    private function runAndCaptureLandlordBackup(): string
    {
        $this->assertSame(0, Artisan::call('vanguard:backup', ['--landlord' => true]));

        return Artisan::output();
    }
}
