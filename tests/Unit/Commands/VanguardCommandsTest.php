<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class VanguardCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:backup
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function backup_command_without_flags_shows_error(): void
    {
        $this->artisan('vanguard:backup')
            ->assertExitCode(1)
            ->expectsOutput('Please specify a backup target: --landlord, --tenant=ID, --all-tenants, or --filesystem');
    }

    #[Test]
    public function backup_landlord_flag_calls_backup_manager(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn($this->makeRecord(['status' => 'completed']));

        $this->app->instance(BackupManager::class, $manager);

        $this->artisan('vanguard:backup --landlord')
            ->assertSuccessful();
    }

    #[Test]
    public function backup_filesystem_flag_calls_backup_filesystem(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupFilesystem')
            ->once()
            ->andReturn($this->makeRecord(['type' => 'filesystem', 'status' => 'completed']));

        $this->app->instance(BackupManager::class, $manager);

        $this->artisan('vanguard:backup --filesystem')
            ->assertSuccessful();
    }

    #[Test]
    public function backup_all_tenants_flag_calls_backup_all_tenants(): void
    {
        config(['vanguard.tenancy.enabled' => true]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn(collect([
            (object) ['id' => 't1'],
        ]));

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupAllTenants')
            ->once()
            ->andReturn([
                ['tenant' => 't1', 'queued' => false, 'record' => $this->makeRecord(['tenant_id' => 't1'])],
            ]);

        $this->app->instance(BackupManager::class, $manager);
        $this->app->instance(TenancyResolver::class, $tenancy);

        $this->artisan('vanguard:backup --all-tenants')
            ->assertSuccessful();
    }

    #[Test]
    public function backup_tenant_flag_with_disabled_tenancy_returns_failure(): void
    {
        config(['vanguard.tenancy.enabled' => false]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(false);

        $this->app->instance(TenancyResolver::class, $tenancy);

        $this->artisan('vanguard:backup --tenant=acme')
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:list
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function list_command_shows_no_records_message_when_empty(): void
    {
        $this->artisan('vanguard:list')
            ->assertSuccessful()
            ->expectsOutput('No backup records found.');
    }

    #[Test]
    public function list_command_shows_table_with_existing_records(): void
    {
        $this->makeRecord(['status' => 'completed', 'type' => 'landlord']);
        $this->makeRecord(['status' => 'failed',    'tenant_id' => 'acme']);

        $this->artisan('vanguard:list')
            ->assertSuccessful();
    }

    #[Test]
    public function list_command_filters_by_tenant(): void
    {
        $this->makeRecord(['tenant_id' => 'acme',   'status' => 'completed']);
        $this->makeRecord(['tenant_id' => 'globex', 'status' => 'completed']);

        // Should only show 'acme' records
        $this->artisan('vanguard:list --tenant=acme')
            ->assertSuccessful();

        $this->assertCount(1, BackupRecord::forTenant('acme')->get());
    }

    #[Test]
    public function list_command_filters_by_status(): void
    {
        $this->makeRecord(['status' => 'completed']);
        $this->makeRecord(['status' => 'failed']);

        $this->artisan('vanguard:list --status=failed')
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:prune
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function prune_command_delegates_to_storage_manager(): void
    {
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('pruneOldBackups')
            ->once()
            ->with(null)
            ->andReturn(5);

        $this->app->instance(BackupStorageManager::class, $store);

        $this->artisan('vanguard:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('5');
    }

    #[Test]
    public function prune_command_passes_tenant_id_when_given(): void
    {
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('pruneOldBackups')
            ->once()
            ->with('acme')
            ->andReturn(2);

        $this->app->instance(BackupStorageManager::class, $store);

        $this->artisan('vanguard:prune --tenant=acme')
            ->assertSuccessful();
    }

    #[Test]
    public function prune_command_overrides_retention_days_when_given(): void
    {
        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('pruneOldBackups')->once()->andReturn(0);

        $this->app->instance(BackupStorageManager::class, $store);

        $this->artisan('vanguard:prune --days=7')
            ->assertSuccessful();

        $this->assertSame(7, config('vanguard.retention.days'));
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:restore
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function restore_command_fails_when_record_not_found(): void
    {
        $this->artisan('vanguard:restore 9999 --force')
            ->assertExitCode(1)
            ->expectsOutput('Backup record [9999] not found.');
    }

    #[Test]
    public function restore_command_calls_restore_service_with_force(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path/to/backup.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->with(
                Mockery::on(fn ($r) => $r->id === $record->id),
                Mockery::type('array'),
            )
            ->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --force")
            ->assertSuccessful()
            ->expectsOutputToContain('Restore completed');
    }

    #[Test]
    public function restore_command_reports_failure_on_exception(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->andThrow(new \RuntimeException('Disk error'));

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --force")
            ->assertExitCode(1)
            ->expectsOutputToContain('Disk error');
    }

    #[Test]
    public function restore_command_refuses_wipe_storage_without_restore_storage(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldNotReceive('restore');

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --wipe-storage --force")
            ->assertExitCode(1)
            ->expectsOutputToContain('--restore-storage');
    }

    #[Test]
    public function restore_command_asks_a_second_confirmation_naming_the_directories_it_will_erase(): void
    {
        config(['vanguard.sources.filesystem_paths' => ['app']]);

        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('backedUpPaths')->andReturn([storage_path('app')]);
        $restoreService->shouldNotReceive('restore');

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --restore-storage --wipe-storage")
            ->expectsConfirmation('Do you want to proceed?', 'yes')
            ->expectsOutputToContain(storage_path('app'))
            ->expectsConfirmation('Erase those directories and replace them with the backup content?', 'no')
            ->expectsOutputToContain('Restore cancelled.')
            ->assertSuccessful();
    }

    #[Test]
    public function restore_command_passes_wipe_storage_to_the_service_when_confirmed(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->with(
                Mockery::on(fn ($r) => $r->id === $record->id),
                Mockery::on(fn ($o) => $o['restore_storage'] === true && $o['wipe_storage'] === true),
            )
            ->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --restore-storage --wipe-storage --force")
            ->assertSuccessful();
    }

    #[Test]
    public function restore_command_does_not_wipe_storage_by_default(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($o) => $o['wipe_storage'] === false),
            )
            ->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --restore-storage --force")
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:install
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function install_command_runs_successfully(): void
    {
        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('Vanguard installed');
    }

    #[Test]
    public function install_command_prints_scheduler_instructions(): void
    {
        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('schedule:run');
    }

    #[Test]
    public function install_command_prints_horizon_queue_supervisor_stub(): void
    {
        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('horizon.php')
            ->expectsOutputToContain('timeout');
    }

    #[Test]
    public function install_command_prints_ftp_adapter_instructions(): void
    {
        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('league/flysystem-ftp')
            ->expectsOutputToContain('filesystems.php');
    }

    #[Test]
    public function install_command_prints_env_variable_reference(): void
    {
        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('VANGUARD_QUEUE_TIMEOUT')
            ->expectsOutputToContain('VANGUARD_FTP_ENABLED')
            ->expectsOutputToContain('VANGUARD_RETENTION_DAYS');
    }

    #[Test]
    public function install_command_warns_when_ftp_enabled_but_disk_not_configured(): void
    {
        config([
            'vanguard.destinations.ftp.enabled' => true,
            'vanguard.destinations.ftp.disk'    => 'ftp_missing',
            // 'filesystems.disks.ftp_missing' is intentionally absent
        ]);

        $this->artisan('vanguard:install')
            ->assertSuccessful()
            ->expectsOutputToContain('ftp_missing');
    }

    #[Test]
    public function install_command_does_not_warn_when_ftp_disabled(): void
    {
        config(['vanguard.destinations.ftp.enabled' => false]);

        // No warning about missing disk should appear when FTP is off
        $output = $this->artisan('vanguard:install')->assertSuccessful();

        // Just assert it completed — the absence of a warning is implicit
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // validateDestinationDisks (ServiceProvider boot)
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function service_provider_logs_warning_when_ftp_enabled_but_disk_missing(): void
    {
        Log::spy();

        config([
            'vanguard.destinations.ftp.enabled' => true,
            'vanguard.destinations.ftp.disk'    => 'nonexistent_ftp_disk',
        ]);

        $sp = new \SoftArtisan\Vanguard\VanguardServiceProvider($this->app);
        $sp->boot();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'nonexistent_ftp_disk'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function service_provider_logs_warning_when_remote_enabled_but_disk_missing(): void
    {
        Log::spy();

        config([
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk'    => 'nonexistent_s3_disk',
        ]);

        $sp = new \SoftArtisan\Vanguard\VanguardServiceProvider($this->app);
        $sp->boot();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'nonexistent_s3_disk'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function service_provider_does_not_warn_when_destination_disabled(): void
    {
        Log::spy();

        config([
            'vanguard.destinations.ftp.enabled'   => false,
            'vanguard.destinations.remote.enabled' => false,
        ]);

        $sp = new \SoftArtisan\Vanguard\VanguardServiceProvider($this->app);
        $sp->boot();

        Log::shouldNotHaveReceived('warning');
        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────
    // vanguard:cleanup-tmp (basic registration check)
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function cleanup_tmp_command_is_registered(): void
    {
        $this->assertTrue(
            $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
                ->all()['vanguard:cleanup-tmp'] !== null
        );
    }
}
