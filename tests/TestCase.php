<?php

namespace SoftArtisan\Vanguard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\VanguardServiceProvider;

use function Orchestra\Testbench\default_skeleton_path;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Before the application boots: a config/vanguard.php left in the
        // Testbench skeleton would be loaded over the package defaults and
        // hide a change to them.
        self::removePublishedPackageFiles();

        parent::setUp();

        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            self::removePublishedPackageFiles();
        }
    }

    /**
     * Remove what `vanguard:install` (vendor:publish) writes into the Testbench
     * skeleton: the config file and the package's own migrations, by name.
     * Nothing else in the skeleton is touched.
     */
    protected static function removePublishedPackageFiles(): void
    {
        $skeleton = default_skeleton_path();

        if ($skeleton === false) {
            return;
        }

        $published = [$skeleton.'/config/vanguard.php'];

        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migration) {
            $published[] = $skeleton.'/database/migrations/'.basename($migration);
        }

        foreach ($published as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
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
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite enforces foreign keys only when asked, and the pragma is a
            // no-op inside a transaction — which RefreshDatabase opens before every
            // test. Set here, the connector applies it at connect time.
            'foreign_key_constraints' => true,
        ]);

        // Vanguard config defaults for tests
        $app['config']->set('vanguard.tenancy.enabled', false);
        $app['config']->set('vanguard.queue.enabled', false);
        $app['config']->set('vanguard.schedule.enabled', false);
        $app['config']->set('vanguard.tmp_path', sys_get_temp_dir().'/vanguard_tests');

        $app['config']->set('vanguard.sources', [
            'landlord_database' => true,
            'tenant_databases' => true,
            'filesystem' => true,
            'filesystem_paths' => ['app'],
            'filesystem_exclude' => [],
        ]);

        $app['config']->set('vanguard.destinations', [
            'local' => [
                'enabled' => true,
                'disk' => 'local',
                'path' => 'vanguard-backups',
            ],
            'remote' => [
                'enabled' => false,
                'disk' => 's3',
                'path' => 'vanguard-backups',
            ],
            'ftp' => [
                'enabled' => false,
                'disk' => 'ftp',
                'path' => 'vanguard-backups',
            ],
        ]);

        $app['config']->set('vanguard.retention', [
            'enabled' => true,
            'days' => 30,
        ]);

        // Use array filesystem for tests (no actual disk writes)
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/vanguard_test_storage',
        ]);
    }

    protected function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Create a BackupRecord with sensible defaults for testing.
     */
    protected function makeRecord(array $attributes = []): BackupRecord
    {
        return BackupRecord::create(array_merge([
            'tenant_id' => null,
            'type' => 'landlord',
            'status' => 'completed',
            'file_path' => 'vanguard-backups/test.tar',
            'file_size' => 1024 * 1024, // 1 MB
            'checksum' => hash('sha256', 'test'),
            'sources' => ['landlord_database'],
            'destinations' => ['local'],
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ], $attributes));
    }

    /**
     * Create a RestoreRecord with sensible defaults for testing.
     */
    protected function makeRestore(array $attributes = []): RestoreRecord
    {
        return RestoreRecord::create(array_merge([
            'backup_id' => null,
            'type' => 'landlord',
            'tenant_id' => null,
            'source' => 'remote',
            'restore_db' => true,
            'restore_storage' => false,
            'verify_checksum' => true,
            'status' => 'pending',
            'requested_by' => 'tester',
        ], $attributes));
    }
}
