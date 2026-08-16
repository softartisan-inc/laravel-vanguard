<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyManager;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenant;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

require_once __DIR__.'/../Support/tenancy_shim.php';

/**
 * Rehearsing a restore into a throwaway database.
 *
 * A restore nobody has ever run is a backup nobody has ever verified. Until
 * this option existed the target was `config('database.default')` and nothing
 * on the command line could move it, so the only way to try a restore was to
 * repoint the whole application at a scratch database — which is why the
 * person who verified that these archives actually restore had to bypass
 * `vanguard:restore` and call the service by hand.
 *
 * What is proven here is the target: the configuration handed to
 * DatabaseDriver::restore() names the throwaway database and not the default
 * one, on the landlord path and the tenant path alike, and nothing outside
 * that one call is repointed.
 */
class RestoreRehearsalTest extends TestCase
{
    private string $landlordStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Vanguard::auth(fn ($request) => true);

        Schema::create('vanguard_test_tenants', function ($table) {
            $table->string('id')->primary();
            $table->string('db_database');
            $table->string('storage_path');
        });

        config([
            'vanguard.tenancy.tenant_model' => FakeTenant::class,
            'vanguard.tenancy.enabled' => true,
            'tenancy.database.tenant_connection_name' => 'tenant',
        ]);

        $this->landlordStoragePath = $this->app->storagePath();

        FakeTenancyManager::reset();
        FakeTenancyManager::instance()->boot('tenant', $this->landlordStoragePath);

        $this->app->instance(TenancyResolver::class, new FakeTenancyResolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        FakeTenancyManager::reset();
        $this->app->useStoragePath($this->landlordStoragePath);
        parent::tearDown();
    }

    /**
     * A RestoreService whose bundle always yields one database component, and
     * whose DatabaseDriver records the configuration it was handed.
     *
     * @param  array<string, mixed>|null  $captured
     */
    private function restoreService(?array &$captured): RestoreService
    {
        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('restore')->once()->andReturnUsing(
            function ($driver, $config, $source) use (&$captured) {
                $captured = ['driver' => $driver, 'config' => $config];
            }
        );

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        return new RestoreService($db, Mockery::mock(StorageDriver::class), $store);
    }

    private function makeTenant(string $id): FakeTenant
    {
        $root = sys_get_temp_dir().'/vanguard_rehearsal_test/'.$id;
        @mkdir($root.'/app', 0777, true);

        return FakeTenant::create([
            'id' => $id,
            'db_database' => "/fake/{$id}.sqlite",
            'storage_path' => $root,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // The service redirects the target
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_landlord_restore_writes_to_the_named_database_instead_of_the_default(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => '/var/lib/production.sqlite'],
        ]);

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $this->assertSame('vanguard_rehearsal', $captured['config']['database']);
        $this->assertNotSame('/var/lib/production.sqlite', $captured['config']['database']);
        $this->assertSame('sqlite', $captured['driver'], 'only the database moves, not the driver');
    }

    #[Test]
    public function a_landlord_restore_without_the_option_still_targets_the_default_database(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => '/var/lib/production.sqlite'],
        ]);

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, ['verify_checksum' => false]);

        $this->assertSame('/var/lib/production.sqlite', $captured['config']['database']);
    }

    #[Test]
    public function redirecting_a_restore_leaves_the_application_configuration_alone(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => '/var/lib/production.sqlite'],
        ]);

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $this->assertSame(
            '/var/lib/production.sqlite',
            config('database.connections.sqlite.database'),
            'a rehearsal must not repoint the application it runs inside',
        );
    }

    #[Test]
    public function a_tenant_restore_writes_to_the_named_database_instead_of_the_tenants(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $this->assertSame('vanguard_rehearsal', $captured['config']['database']);
        $this->assertNotSame($tenant->dbConfig()['database'], $captured['config']['database']);
    }

    #[Test]
    public function a_tenant_restore_redirects_the_tenants_own_connection_and_moves_nothing_else(): void
    {
        // The landlord path reads config('database.connections.<default>');
        // the tenant path reads whatever tenancy()->initialize() installed for
        // this tenant. It is the second one that has to be redirected — a
        // rehearsal that redirected the landlord connection instead would write
        // the tenant's data into a database on the wrong server, under the
        // wrong credentials, and most likely succeed at it.
        $tenant = $this->makeTenant('tenant-b');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $expected = $tenant->dbConfig();
        $expected['database'] = 'vanguard_rehearsal';

        // Everything but the database name, byte for byte: the host, the port,
        // the credentials, the driver and the tenant marker are the tenant's
        // own, so the rehearsal exercises the same server and the same client
        // as the restore it stands in for.
        $this->assertSame($expected, $captured['config']);
        $this->assertSame($tenant->dbConfig()['driver'], $captured['driver']);
    }

    #[Test]
    public function a_tenant_restore_without_the_option_still_targets_the_tenants_own_database(): void
    {
        $tenant = $this->makeTenant('tenant-c');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, ['verify_checksum' => false]);

        $this->assertSame($tenant->dbConfig(), $captured['config']);
    }

    #[Test]
    public function redirecting_a_tenant_restore_leaves_the_tenant_connection_config_alone(): void
    {
        // Read from inside the restore, not after it: the tenancy window is
        // the only moment the tenant connection exists, and it is closed by
        // the time restore() returns. If the redirection were written back
        // into config() rather than kept on a copy, everything else running
        // inside that window — a model, a queued job, the next tenant in a
        // backupAllTenants() loop — would silently start reading and writing
        // the rehearsal database.
        $tenant = $this->makeTenant('tenant-d');
        $connection = config('tenancy.database.tenant_connection_name');

        $record = $this->makeRecord(['type' => 'tenant', 'tenant_id' => $tenant->getTenantKey(), 'checksum' => null]);

        $liveConfig = null;

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('restore')->once()->andReturnUsing(
            function () use (&$liveConfig, $connection) {
                $liveConfig = config("database.connections.{$connection}");
            }
        );

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->once();

        (new RestoreService($db, Mockery::mock(StorageDriver::class), $store))->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $this->assertSame(
            $tenant->dbConfig(),
            $liveConfig,
            'a rehearsal must not repoint the tenant connection it borrows',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // The command carries it, refuses nonsense, and says where it writes
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_command_hands_the_named_database_to_the_service(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($o) => ($o['database'] ?? null) === 'vanguard_rehearsal'),
            )
            ->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --database=vanguard_rehearsal --force")
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_leaves_the_target_alone_when_the_option_is_absent(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($o) => ($o['database'] ?? null) === null),
            )
            ->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --force")->assertSuccessful();
    }

    #[Test]
    public function the_command_says_unmissably_which_database_it_is_about_to_write_to(): void
    {
        // A rehearsal that silently hits production is worse than no rehearsal.
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldReceive('restore')->once()->andReturn(true);

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --database=vanguard_rehearsal --force")
            ->expectsOutputToContain('vanguard_rehearsal')
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_refuses_a_database_name_that_is_not_a_plain_identifier(): void
    {
        // This value reaches a shell command line. An allowlist, not escaping.
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldNotReceive('restore');

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --database='rehearsal; rm -rf /' --force")
            ->assertExitCode(1)
            ->expectsOutputToContain('--database must be a plain database identifier');
    }

    #[Test]
    public function the_command_refuses_an_empty_database_name(): void
    {
        $record = $this->makeRecord(['status' => 'completed', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(RestoreService::class);
        $restoreService->shouldNotReceive('restore');

        $this->app->instance(RestoreService::class, $restoreService);

        $this->artisan("vanguard:restore {$record->id} --database= --force")
            ->assertExitCode(1)
            ->expectsOutputToContain('--database must be a plain database identifier');
    }

    // ─────────────────────────────────────────────────────────────
    // Console only: the option must not exist anywhere else
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_api_refuses_a_database_on_presence_alone(): void
    {
        // Same rule as wipe_storage, for the same reason. A dashboard user who
        // believes they are rehearsing into a scratch database, and is silently
        // given the real one, gets the worst possible outcome of this feature:
        // production overwritten by a restore nobody thought was real. Refused
        // on presence, not on value — being told the parameter is meaningless
        // here is the only answer that cannot be misread.
        Queue::fake();

        $backup = $this->makeRecord(['type' => 'landlord', 'status' => 'completed', 'file_path' => 'path.tar']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
            'database' => 'vanguard_rehearsal',
        ])->assertStatus(400);

        $this->assertSame(0, RestoreRecord::count(), 'a refused restore must leave no history row');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_queued_restore_never_carries_a_database(): void
    {
        // The job builds the option array itself, from the columns of the
        // history row. There is no column to carry a redirect and there must
        // never be one: a redirected restore is something an operator does at
        // a console, in front of the machine, not something that can be left
        // sitting in a queue.
        Queue::fake();

        $backup = $this->makeRecord(['type' => 'landlord', 'status' => 'completed', 'file_path' => 'path.tar']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
        ])->assertStatus(202);

        $restore = RestoreRecord::firstOrFail();

        $this->assertArrayNotHasKey('database', $restore->getAttributes());

        $captured = null;

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnUsing(
            function ($record, $options) use (&$captured) {
                $captured = $options;

                return true;
            }
        );

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('database', $captured);
    }
}
