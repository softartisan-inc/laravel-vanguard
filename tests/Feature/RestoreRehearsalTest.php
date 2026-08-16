<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyManager;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenant;
use SoftArtisan\Vanguard\Tests\TestCase;

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
}
