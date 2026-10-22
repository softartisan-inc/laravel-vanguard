<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The package defaults, read without a published config/vanguard.php.
 *
 * `vanguard:install` publishes the config into the Testbench skeleton, and a
 * copy left there would be loaded over config/vanguard.php: a changed default
 * in the package would then go unnoticed. The base TestCase removes the copy;
 * the first test publishes one so the second proves it is gone.
 */
class DefaultConfigurationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_default_config_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        putenv('PGPASSWORD');
        parent::tearDown();
    }

    #[Test]
    public function install_publishes_the_config_into_the_skeleton(): void
    {
        $this->artisan('vanguard:install --no-interaction')->assertSuccessful();

        $this->assertFileExists(config_path('vanguard.php'));
    }

    #[Test]
    public function without_a_published_config_the_version_check_is_off_and_the_dump_is_unchanged(): void
    {
        $this->assertFileDoesNotExist(
            config_path('vanguard.php'),
            'A published config/vanguard.php survived an earlier test and masks the package defaults.',
        );

        $this->assertSame('off', config('vanguard.dump.pgsql_version_check'));

        // A pg_dump older than the server, which any check would notice.
        $calls = $this->tmpDir.'/calls.log';
        $binary = $this->tmpDir.'/pg_dump';
        file_put_contents($binary, "#!/bin/sh\n"
            .'if [ "$1" = "--version" ]; then echo version >> '.escapeshellarg($calls)."; echo 'pg_dump (PostgreSQL) 16.4'; exit 0; fi\n"
            .'echo dump >> '.escapeshellarg($calls)."\n"
            ."echo 'CREATE TABLE marker (id int);'\n");
        chmod($binary, 0755);
        config()->set('vanguard.binaries.pg_dump', $binary);

        $driver = new class extends DatabaseDriver
        {
            public int $serverQueries = 0;

            protected function pgServerMajorVersion(array $c): ?int
            {
                $this->serverQueries++;

                return 18;
            }
        };

        $driver->dump('pgsql', [
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'my_app',
            'username' => 'app_user',
            'password' => 'secret',
        ], $this->tmpDir.'/default.sql.gz');

        // The 2.6.1 behaviour, call for call: one pg_dump run, no version
        // probe, no extra connection to the server.
        $this->assertSame(['dump'], file($calls, FILE_IGNORE_NEW_LINES));
        $this->assertSame(0, $driver->serverQueries);
    }
}
