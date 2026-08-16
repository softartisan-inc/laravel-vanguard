<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Vanguard's own tables live on the central connection, and
 * Vanguard::centralConnection() exists so there is one place that resolves it.
 *
 * stancl/tenancy swaps `database.default` for the duration of a tenancy
 * window, so a model that names no connection re-resolves it on every query
 * and lands wherever the swap left it — a database where `vanguard_backups`
 * does not exist. The swap is simulated here by moving `database.default` to
 * an empty connection, which is exactly its observable effect.
 */
class CentralConnectionPinningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');

        config(['database.connections.tenant_decoy' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
    }

    protected function tearDown(): void
    {
        config(['database.default' => 'sqlite']);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Name the connection the data is really on, then swap the default away
     * from it the way a tenancy window does.
     */
    protected function divertTheDefaultConnection(): void
    {
        config([
            'tenancy.database.central_connection' => 'sqlite',
            'database.default' => 'tenant_decoy',
        ]);
    }

    protected function withTenants(array $keys): void
    {
        $tenants = collect($keys)->map(fn (string $key) => new class($key)
        {
            public function __construct(public string $key) {}

            public function getTenantKey(): string
            {
                return $this->key;
            }
        });

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn($tenants);
        $tenancy->shouldReceive('tenantSchedule')->andReturn(null);

        $this->app->instance(TenancyResolver::class, $tenancy);
    }

    // ─── BackupsApiController ────────────────────────────────────

    #[Test]
    public function the_stats_endpoint_reads_the_central_connection(): void
    {
        $this->makeRecord();
        $this->divertTheDefaultConnection();

        $this->getJson('/vanguard/api/stats')
            ->assertOk()
            ->assertJsonPath('total_backups', 1);
    }

    #[Test]
    public function the_backups_list_reads_the_central_connection(): void
    {
        $record = $this->makeRecord();
        $this->divertTheDefaultConnection();

        $this->getJson('/vanguard/api/backups')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $record->id);
    }

    #[Test]
    public function the_tenants_endpoint_reads_the_central_connection(): void
    {
        $this->withTenants(['9001']);
        $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);
        $this->divertTheDefaultConnection();

        $this->getJson('/vanguard/api/tenants')
            ->assertOk()
            ->assertJsonPath('tenants.0.total_backups', 1);
    }

    #[Test]
    public function deleting_a_backup_reads_the_central_connection(): void
    {
        $record = $this->makeRecord();
        $this->divertTheDefaultConnection();

        $this->deleteJson("/vanguard/api/backups/{$record->id}")->assertOk();

        $this->assertNull(BackupRecord::on('sqlite')->find($record->id));
    }

    // ─── BackupStorageManager ────────────────────────────────────

    #[Test]
    public function pruning_reads_the_central_connection(): void
    {
        $this->makeRecord(['created_at' => now()->subDays(90)]);
        $this->divertTheDefaultConnection();

        $this->assertSame(1, app(BackupStorageManager::class)->pruneOldBackups());
    }

    // ─── RunRestoreJob ───────────────────────────────────────────

    #[Test]
    public function the_restore_job_writes_its_phases_to_the_central_connection(): void
    {
        Event::fake();

        // Present but null: config('tenancy.database.central_connection',
        // config('database.default')) answers null for a key that exists, and
        // RestoreRecord::on(null) then resolves database.default lazily — at
        // query time, from inside the tenancy window, which is the one place
        // the swap is guaranteed to be active. This is the site the branch's
        // own docblock documents as wrong.
        config(['tenancy.database.central_connection' => null]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id]);

        $observedPhase = null;

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnUsing(function ($record, array $options) use ($restore, &$observedPhase) {
            // What stancl/tenancy does for the duration of
            // tenancy()->initialize() / end().
            config(['database.default' => 'tenant_decoy']);

            try {
                ($options['on_phase'])('restoring database');

                // Read back from inside the window, on the connection the
                // catalogue really lives on. An unpinned write would have
                // thrown before reaching this line.
                $observedPhase = RestoreRecord::on('sqlite')->find($restore->id)->phase;
            } finally {
                config(['database.default' => 'sqlite']);
            }

            return true;
        });

        (new RunRestoreJob($restore->id))->handle($service);

        $fresh = RestoreRecord::on('sqlite')->find($restore->id);

        $this->assertSame(
            'restoring database',
            $observedPhase,
            'the phase write landed somewhere other than the central connection',
        );
        // markCompleted() clears the phase, so the end state is the proof the
        // job never had to fall into its catch block.
        $this->assertSame('completed', $fresh->status);
        $this->assertNull($fresh->error);
    }
}
