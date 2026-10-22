<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The pg_dump version check against a real PostgreSQL server and the real
 * pg_dump on this host.
 *
 * Skipped unless a connection is provided (same variables as
 * PostgresRowLevelSecurityIntegrationTest):
 *
 *   VANGUARD_PG_TEST_HOST=127.0.0.1 VANGUARD_PG_TEST_PORT=5432 \
 *   VANGUARD_PG_TEST_USER=postgres VANGUARD_PG_TEST_PASSWORD=... \
 *   vendor/bin/phpunit --filter PgDumpVersionCheckIntegrationTest
 *
 * The local pg_dump must be the server's major or newer.
 */
class PgDumpVersionCheckIntegrationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('VANGUARD_PG_TEST_HOST')) {
            $this->markTestSkipped('Set VANGUARD_PG_TEST_HOST (and _PORT, _USER, _PASSWORD) to run against a real PostgreSQL server.');
        }

        $this->tmp = sys_get_temp_dir().'/vanguard_pgver_'.uniqid();
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            exec('rm -rf '.escapeshellarg($this->tmp));
        }

        putenv('PGPASSWORD');
        parent::tearDown();
    }

    private function connection(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => (string) getenv('VANGUARD_PG_TEST_HOST'),
            'port' => (int) (getenv('VANGUARD_PG_TEST_PORT') ?: 5432),
            'database' => 'postgres',
            'username' => (string) getenv('VANGUARD_PG_TEST_USER') ?: 'postgres',
            'password' => (string) getenv('VANGUARD_PG_TEST_PASSWORD'),
        ];
    }

    private function serverMajor(): int
    {
        $c = $this->connection();
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname=postgres", $c['username'], $c['password']);

        return intdiv((int) $pdo->query('SHOW server_version_num')->fetchColumn(), 10000);
    }

    #[Test]
    public function the_real_pg_dump_and_server_pass_strict_and_the_dump_runs(): void
    {
        config()->set('vanguard.dump.pgsql_version_check', 'strict');

        $driver = new DatabaseDriver;
        $report = $driver->pgVersionReport($this->connection());

        $this->assertSame($this->serverMajor(), $report['server']);
        $this->assertTrue($report['compatible'], (string) $report['reason']);

        $result = $driver->dump('pgsql', $this->connection(), $this->tmp.'/real.sql.gz');

        $this->assertStringContainsString('PostgreSQL database dump', gzdecode(file_get_contents($result)));
    }

    #[Test]
    public function an_older_pg_dump_is_refused_against_the_real_server(): void
    {
        $server = $this->serverMajor();
        $fake = $this->tmp.'/pg_dump';
        file_put_contents($fake, "#!/bin/sh\n"
            .'if [ "$1" = "--version" ]; then echo "pg_dump (PostgreSQL) '.($server - 2).'.1"; exit 0; fi'."\n"
            .'echo dump-ran > '.escapeshellarg($this->tmp.'/ran')."\n");
        chmod($fake, 0755);

        config()->set('vanguard.binaries.pg_dump', $fake);
        config()->set('vanguard.dump.pgsql_version_check', 'strict');

        try {
            (new DatabaseDriver)->dump('pgsql', $this->connection(), $this->tmp.'/old.sql.gz');
            $this->fail('An older pg_dump must be refused in strict mode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("cannot dump a PostgreSQL {$server} server", $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tmp.'/ran');
    }
}
