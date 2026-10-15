<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The landlord restore writes through vanguard.database.restore_connection
 * when it is set, and through the default connection — exactly as before —
 * when not. --database (rehearsal) still applies on top of whichever
 * connection was chosen: only the database name moves.
 */
class RestoreDedicatedConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        config([
            'vanguard.database.restore_connection' => null,
            'database.connections.sqlite_owner' => [
                'driver' => 'sqlite',
                'database' => '/var/lib/app.sqlite',
                'username' => 'owner_user',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
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

    #[Test]
    public function a_landlord_restore_uses_the_dedicated_restore_connection_when_one_is_set(): void
    {
        config(['vanguard.database.restore_connection' => 'sqlite_owner']);

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, ['verify_checksum' => false]);

        $this->assertSame('owner_user', $captured['config']['username']);
        $this->assertSame('/var/lib/app.sqlite', $captured['config']['database']);
        $this->assertSame('sqlite', $captured['driver'], 'the driver is read from the dedicated connection');
    }

    #[Test]
    public function a_rehearsal_redirects_the_dedicated_restore_connection_and_keeps_its_credentials(): void
    {
        config(['vanguard.database.restore_connection' => 'sqlite_owner']);

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, [
            'verify_checksum' => false,
            'database' => 'vanguard_rehearsal',
        ]);

        $this->assertSame('vanguard_rehearsal', $captured['config']['database']);
        $this->assertSame('owner_user', $captured['config']['username']);
        $this->assertSame('/var/lib/app.sqlite', config('database.connections.sqlite_owner.database'));
    }

    #[Test]
    public function without_a_restore_connection_the_landlord_restore_is_unchanged(): void
    {
        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $captured = null;
        $this->restoreService($captured)->restore($record, ['verify_checksum' => false]);

        $this->assertSame(config('database.connections.sqlite'), $captured['config']);
        // The connection name is what reaches the driver argument today; that
        // is kept as is when the setting is unset.
        $this->assertSame(config('database.default'), $captured['driver']);
    }

    #[Test]
    public function an_undeclared_restore_connection_is_refused_before_anything_is_written(): void
    {
        config(['vanguard.database.restore_connection' => 'nowhere']);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('restore');

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp');

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vanguard.database.restore_connection');

        (new RestoreService($db, Mockery::mock(StorageDriver::class), $store))
            ->restore($record, ['verify_checksum' => false]);
    }
}
