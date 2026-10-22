<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The landlord dump runs through vanguard.database.backup_connection when it
 * is set, and through the landlord connection — exactly as before — when not.
 *
 * Under Row-Level Security the application's role sees one tenant's rows at a
 * time; a dump through it is a backup of that view, not of the database. The
 * proof here is the configuration DatabaseDriver::dump() actually receives.
 */
class BackupDedicatedConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        config([
            'vanguard.database.backup_connection' => null,
            'database.connections.sqlite_backup' => [
                'driver' => 'sqlite',
                'database' => '/var/lib/app.sqlite',
                'username' => 'backup_user',
                'password' => 'backup-secret',
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
    private function manager(?array &$captured): BackupManager
    {
        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('dump')->once()->andReturnUsing(
            function ($driver, $config, $destination) use (&$captured) {
                $captured = ['driver' => $driver, 'config' => $config];

                return $destination;
            }
        );

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_dedicated/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldReceive('bundle')->once()->andReturn([
            'local_path' => 'vanguard-backups/x.tar',
            'remote_path' => null,
            'ftp_path' => null,
            'size' => 10,
            'checksum' => hash('sha256', 'x'),
        ]);

        return new BackupManager($db, Mockery::mock(StorageDriver::class), $store, new TenancyResolver);
    }

    #[Test]
    public function the_landlord_dump_uses_the_dedicated_backup_connection_when_one_is_set(): void
    {
        config(['vanguard.database.backup_connection' => 'sqlite_backup']);

        $captured = null;
        $this->manager($captured)->backupLandlord(['include_filesystem' => false]);

        $this->assertSame('backup_user', $captured['config']['username']);
        $this->assertSame(config('database.connections.sqlite_backup'), $captured['config']);
        $this->assertSame('sqlite', $captured['driver']);
    }

    #[Test]
    public function without_a_backup_connection_the_landlord_dump_receives_the_landlord_connection_unchanged(): void
    {
        $captured = null;
        $this->manager($captured)->backupLandlord(['include_filesystem' => false]);

        $this->assertSame(config('database.connections.sqlite'), $captured['config']);
        $this->assertSame((new TenancyResolver)->landlordDbConfig(), $captured['config']);
    }

    #[Test]
    public function an_undeclared_backup_connection_fails_the_backup_before_any_dump(): void
    {
        config(['vanguard.database.backup_connection' => 'nowhere']);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('dump');

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('tmpPath')->andReturnUsing(fn ($f) => sys_get_temp_dir().'/vg_dedicated/'.$f);
        $store->shouldReceive('cleanTmp')->andReturnNull();
        $store->shouldNotReceive('bundle');

        $manager = new BackupManager($db, Mockery::mock(StorageDriver::class), $store, new TenancyResolver);

        try {
            $manager->backupLandlord(['include_filesystem' => false]);
            $this->fail('The backup must fail on an undeclared backup connection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vanguard.database.backup_connection', $e->getMessage());
        }

        $this->assertDatabaseHas('vanguard_backups', ['type' => 'landlord', 'status' => 'failed']);
    }
}
