<?php

namespace SoftArtisan\Vanguard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use SoftArtisan\Vanguard\VanguardServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            VanguardServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // SQLite in-memory for tests
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Vanguard config defaults for tests
        $app['config']->set('vanguard.tenancy.enabled', false);
        $app['config']->set('vanguard.queue.enabled', false);
        $app['config']->set('vanguard.schedule.enabled', false);
        $app['config']->set('vanguard.tmp_path', sys_get_temp_dir().'/vanguard_tests');

        $app['config']->set('vanguard.sources', [
            'landlord_database' => true,
            'tenant_databases'  => true,
            'filesystem'        => true,
            'filesystem_paths'  => ['app'],
            'filesystem_exclude' => [],
        ]);

        $app['config']->set('vanguard.destinations', [
            'local' => [
                'enabled' => true,
                'disk'    => 'local',
                'path'    => 'vanguard-backups',
            ],
            'remote' => [
                'enabled' => false,
                'disk'    => 's3',
                'path'    => 'vanguard-backups',
            ],
        ]);

        $app['config']->set('vanguard.retention', [
            'enabled' => true,
            'days'    => 30,
        ]);

        // Use array filesystem for tests (no actual disk writes)
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root'   => sys_get_temp_dir().'/vanguard_test_storage',
        ]);
    }

    protected function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Create a BackupRecord with sensible defaults for testing.
     */
    protected function makeRecord(array $attributes = []): \SoftArtisan\Vanguard\Models\BackupRecord
    {
        return \SoftArtisan\Vanguard\Models\BackupRecord::create(array_merge([
            'tenant_id'    => null,
            'type'         => 'landlord',
            'status'       => 'completed',
            'file_path'    => 'vanguard-backups/test.tar',
            'file_size'    => 1024 * 1024, // 1 MB
            'checksum'     => hash('sha256', 'test'),
            'sources'      => ['landlord_database'],
            'destinations' => ['local'],
            'started_at'   => now()->subMinutes(2),
            'completed_at' => now(),
        ], $attributes));
    }
}
