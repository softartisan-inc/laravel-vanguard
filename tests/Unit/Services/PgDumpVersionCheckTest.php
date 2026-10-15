<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * pg_dump refuses to dump a server of a newer major version, and it says so
 * only once the dump has started, in a line of stderr. The opt-in check
 * (vanguard.dump.pgsql_version_check) compares the two majors before the dump
 * and names the setting that fixes it. Off — the default — it does nothing at
 * all: no `pg_dump --version`, no extra connection to the server.
 */
class PgDumpVersionCheckTest extends TestCase
{
    private string $tmpDir;

    private string $calls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_pg_version_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->calls = $this->tmpDir.'/calls.log';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        putenv('PGPASSWORD');
        parent::tearDown();
    }

    /**
     * A pg_dump that reports $versionLine to --version, writes a one-line dump
     * otherwise, and logs every invocation to $this->calls.
     */
    private function fakePgDump(string $versionLine): string
    {
        $path = $this->tmpDir.'/pg_dump';
        $log = escapeshellarg($this->calls);

        file_put_contents($path, "#!/bin/sh\n"
            .'if [ "$1" = "--version" ]; then echo version >> '.$log.'; echo '.escapeshellarg($versionLine)."; exit 0; fi\n"
            .'echo dump >> '.$log."\n"
            ."echo 'CREATE TABLE marker (id int);'\n");
        chmod($path, 0755);

        config()->set('vanguard.binaries.pg_dump', $path);

        return $path;
    }

    /**
     * A driver whose server answers with $serverMajor, without a server.
     */
    private function driverForServer(?int $serverMajor): DatabaseDriver
    {
        return new class($serverMajor) extends DatabaseDriver
        {
            public int $serverQueries = 0;

            public function __construct(private ?int $serverMajor) {}

            protected function pgServerMajorVersion(array $c): ?int
            {
                $this->serverQueries++;

                return $this->serverMajor;
            }
        };
    }

    private function pgConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'shop',
            'username' => 'postgres',
            'password' => 's3cret',
        ];
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        return is_file($this->calls) ? file($this->calls, FILE_IGNORE_NEW_LINES) : [];
    }

    #[Test]
    public function off_by_default_it_neither_asks_pg_dump_nor_the_server_anything(): void
    {
        $this->fakePgDump('pg_dump (PostgreSQL) 16.4');
        $driver = $this->driverForServer(18);

        $this->assertSame('off', config('vanguard.dump.pgsql_version_check'));

        $driver->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/off.sql.gz');

        // The pre-existing behaviour, call for call: one pg_dump run.
        $this->assertSame(['dump'], $this->calls());
        $this->assertSame(0, $driver->serverQueries);
    }

    #[Test]
    public function strict_refuses_an_older_pg_dump_before_any_dump_runs(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');
        $binary = $this->fakePgDump('pg_dump (PostgreSQL) 16.4');
        $dest = $this->tmpDir.'/strict.sql.gz';

        try {
            $this->driverForServer(18)->dump('pgsql', $this->pgConfig(), $dest);
            $this->fail('A pg_dump 16 against a server 18 must be refused in strict mode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('16', $e->getMessage());
            $this->assertStringContainsString('18', $e->getMessage());
            $this->assertStringContainsString('VANGUARD_PG_DUMP_BINARY', $e->getMessage());
            $this->assertStringContainsString($binary, $e->getMessage());
        }

        $this->assertNotContains('dump', $this->calls(), 'the dump itself must never start');
        $this->assertFileDoesNotExist($dest);
    }

    #[Test]
    public function warn_logs_the_mismatch_and_dumps_anyway(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'warn');
        $this->fakePgDump('pg_dump (PostgreSQL) 16.4');
        Log::spy();

        $this->driverForServer(18)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/warn.sql.gz');

        $this->assertSame(['version', 'dump'], $this->calls());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, '16') && str_contains($message, '18')
                && str_contains($message, 'VANGUARD_PG_DUMP_BINARY')
        )->once();
    }

    #[Test]
    public function a_newer_pg_dump_than_the_server_passes_strict(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');
        $this->fakePgDump('pg_dump (PostgreSQL) 18.6 (Debian 18.6-1.pgdg13+1)');

        $result = $this->driverForServer(17)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/newer.sql.gz');

        $this->assertSame(['version', 'dump'], $this->calls());
        $this->assertStringContainsString('CREATE TABLE marker', gzdecode(file_get_contents($result)));
    }

    #[Test]
    public function the_same_major_passes_strict(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');
        $this->fakePgDump('pg_dump (PostgreSQL) 18.1');

        $this->driverForServer(18)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/same.sql.gz');

        $this->assertSame(['version', 'dump'], $this->calls());
    }

    #[Test]
    public function an_unreadable_version_is_not_a_proven_mismatch_and_never_blocks(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');
        $this->fakePgDump('something that is not a version');
        Log::spy();

        $this->driverForServer(18)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/unknown.sql.gz');

        $this->assertContains('dump', $this->calls());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'could not')
        )->once();
    }

    #[Test]
    public function an_unreachable_server_never_blocks_either(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');
        $this->fakePgDump('pg_dump (PostgreSQL) 16.4');
        Log::spy();

        $this->driverForServer(null)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/unreachable.sql.gz');

        $this->assertContains('dump', $this->calls());
    }

    #[Test]
    public function it_reads_the_major_of_old_and_new_version_strings(): void
    {
        $driver = new class extends DatabaseDriver
        {
            public function major(string $binary): ?int
            {
                return $this->pgDumpMajorVersion($binary);
            }
        };

        $this->assertSame(18, $driver->major($this->fakePgDump('pg_dump (PostgreSQL) 18.6 (Ubuntu 18.6-0ubuntu0.26.04.1)')));
        $this->assertSame(9, $driver->major($this->fakePgDump('pg_dump (PostgreSQL) 9.6.24')));
        $this->assertSame(17, $driver->major($this->fakePgDump('pg_dump (PostgreSQL) 17beta1')));
        $this->assertNull($driver->major($this->fakePgDump('nonsense')));
        $this->assertNull($driver->major($this->tmpDir.'/no-such-pg_dump'));
    }

    #[Test]
    public function an_unknown_mode_warns_and_never_blocks(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'stritc');
        $this->fakePgDump('pg_dump (PostgreSQL) 16.4');
        Log::spy();

        $this->driverForServer(18)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/typo.sql.gz');

        $this->assertContains('dump', $this->calls());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'stritc')
        )->atLeast()->once();
    }
}
