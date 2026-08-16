<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunTenantBackupJob;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class BackupRunOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_forwards_no_filesystem_to_the_queued_job(): void
    {
        // Parity with `vanguard:backup --landlord --no-filesystem`. The option
        // existed only on the CLI: the endpoint dispatched an empty option
        // array, so every dashboard backup took the whole storage tree.
        config(['vanguard.queue.enabled' => true]);
        Queue::fake();

        $this->postJson('/vanguard/api/backups/run', [
            'type' => 'landlord',
            'include_filesystem' => false,
        ])->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(
            RunTenantBackupJob::class,
            fn ($job) => $job->tenantId === '__landlord__' && $job->options['include_filesystem'] === false,
        );
    }

    #[Test]
    public function it_includes_the_filesystem_by_default(): void
    {
        config(['vanguard.queue.enabled' => true]);
        Queue::fake();

        $this->postJson('/vanguard/api/backups/run', ['type' => 'landlord'])->assertOk();

        Queue::assertPushed(
            RunTenantBackupJob::class,
            fn ($job) => $job->options['include_filesystem'] === true,
        );
    }

    #[Test]
    public function it_queues_a_filesystem_only_backup(): void
    {
        // Parity with `vanguard:backup --filesystem`, which the endpoint ran
        // inline whatever the queue configuration said.
        config(['vanguard.queue.enabled' => true]);
        Queue::fake();

        $this->postJson('/vanguard/api/backups/run', ['type' => 'filesystem'])
            ->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(RunTenantBackupJob::class, fn ($job) => $job->tenantId === '__filesystem__');
    }

    #[Test]
    public function it_forces_the_queue_even_when_the_configuration_disables_it(): void
    {
        // Parity with `vanguard:backup --queue`.
        config(['vanguard.queue.enabled' => false]);
        Queue::fake();

        $this->postJson('/vanguard/api/backups/run', ['type' => 'landlord', 'queue' => true])
            ->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(RunTenantBackupJob::class);
    }

    #[Test]
    public function it_runs_inline_when_the_queue_is_disabled_and_not_forced(): void
    {
        config(['vanguard.queue.enabled' => false]);
        Queue::fake();

        $record = $this->makeRecord(['type' => 'landlord']);

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')
            ->once()
            ->with(['include_filesystem' => false])
            ->andReturn($record);
        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', [
            'type' => 'landlord',
            'include_filesystem' => false,
        ])->assertOk()->assertJsonPath('record.id', $record->id);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_forwards_the_options_to_an_inline_tenant_backup(): void
    {
        config(['vanguard.queue.enabled' => false]);

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);
        $tenant = new class
        {
            public function getTenantKey()
            {
                return '9001';
            }
        };

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('findTenant')->once()->with('9001')->andReturn($tenant);
        $this->app->instance(TenancyResolver::class, $tenancy);

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupTenant')
            ->once()
            ->with($tenant, ['include_filesystem' => false])
            ->andReturn($record);
        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', [
            'type' => 'tenant',
            'tenant_id' => '9001',
            'include_filesystem' => false,
        ])->assertOk();
    }

    #[Test]
    public function it_forwards_the_options_to_an_all_tenants_run(): void
    {
        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupAllTenants')
            ->once()
            ->with(['include_filesystem' => false])
            ->andReturn([]);
        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', [
            'type' => 'all-tenants',
            'include_filesystem' => false,
        ])->assertOk()->assertJson(['results' => []]);
    }
}
