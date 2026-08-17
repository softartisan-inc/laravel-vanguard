<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * `vanguard:backup` used to print a single "Path :" line naming the local
 * copy — which does not exist on the setup the documentation recommends
 * (VANGUARD_LOCAL_ENABLED=false, remote only). These pin the replacement:
 * every destination the archive actually reached is named, and a completed
 * record that reached none of them is unmistakable rather than a blank line
 * under a green "Completed".
 */
class BackupDestinationConsoleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_prints_the_remote_path_when_only_remote_was_reached(): void
    {
        $output = $this->runLandlordBackupWith([
            'file_path' => null,
            'remote_path' => 'remote/tenant9.tar',
            'ftp_path' => null,
        ]);

        $this->assertStringContainsString('remote/tenant9.tar', $output);

        // The old defect: a "Path     :" label followed by nothing at all.
        $this->assertDoesNotMatchRegularExpression('/Path\s*:\s*$/m', $output);
        $this->assertStringNotContainsString('Path     :', $output);
    }

    #[Test]
    public function it_prints_every_destination_the_archive_reached(): void
    {
        $output = $this->runLandlordBackupWith([
            'file_path' => 'local/tenant9.tar',
            'remote_path' => 'remote/tenant9.tar',
            'ftp_path' => 'ftp/tenant9.tar',
        ]);

        $this->assertStringContainsString('local/tenant9.tar', $output);
        $this->assertStringContainsString('remote/tenant9.tar', $output);
        $this->assertStringContainsString('ftp/tenant9.tar', $output);
    }

    #[Test]
    public function it_is_unmistakable_when_a_completed_record_reached_no_destination(): void
    {
        $output = $this->runLandlordBackupWith([
            'file_path' => null,
            'remote_path' => null,
            'ftp_path' => null,
        ]);

        $this->assertStringContainsString('reached NO destination', $output);
        $this->assertStringContainsString('no file behind this record', $output);
    }

    /**
     * Bind a BackupManager returning a landlord record with the given
     * destination attributes, run `vanguard:backup --landlord`, and return
     * everything it printed.
     */
    private function runLandlordBackupWith(array $attributes): string
    {
        $record = $this->makeRecord(array_merge(['status' => 'completed'], $attributes));

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')->once()->andReturn($record);

        $this->app->instance(BackupManager::class, $manager);

        Artisan::call('vanguard:backup', ['--landlord' => true]);

        return Artisan::output();
    }
}
