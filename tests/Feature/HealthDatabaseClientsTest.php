<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakeTenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Say it before the incident, not during it.
 *
 * Nothing on the health screen said a restore was impossible on this host:
 * the image shipped PHP extensions and no database client, backups fell back
 * to PDO and went green every night, and the missing client only surfaced the
 * day an operator needed an archive back. The clients are evidence like
 * everything else on that screen — looked for where the driver looks for
 * them, and reported with the path each operation would actually take.
 */
class HealthDatabaseClientsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);

        $this->tmpDir = sys_get_temp_dir().'/vanguard_health_clients_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    private function presentBinary(string $name): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }

    private function absentBinary(string $name): string
    {
        return $this->tmpDir.'/no-such-'.$name;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function driverRow(string $driver): ?array
    {
        $response = $this->getJson('/vanguard/api/health')->assertOk();

        return collect($response->json('database_clients'))->firstWhere('driver', $driver);
    }

    private function useMysqlConnection(): void
    {
        config([
            'database.connections.vanguard_health_mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'database' => 'shop',
                'username' => 'root',
            ],
            // Only the central connection is redirected: 'database.default'
            // is what the test harness migrates and rolls back on.
            'tenancy.database.central_connection' => 'vanguard_health_mysql',
        ]);
    }

    #[Test]
    public function it_reports_both_clients_of_the_configured_driver(): void
    {
        $this->useMysqlConnection();

        config([
            'vanguard.binaries.mysqldump' => $this->presentBinary('mysqldump'),
            'vanguard.binaries.mysql' => $this->presentBinary('mysql'),
        ]);

        $row = $this->driverRow('mysql');

        $this->assertNotNull($row, 'the driver Vanguard would dump must appear on the health screen');
        $this->assertSame('mysqldump', $row['dump']['client']);
        $this->assertTrue($row['dump']['present']);
        $this->assertSame('client', $row['dump']['via']);
        $this->assertSame('mysql', $row['restore']['client']);
        $this->assertTrue($row['restore']['present']);
        $this->assertSame('client', $row['restore']['via']);
        $this->assertTrue($row['ok']);
        $this->assertFalse($row['degraded']);
        $this->assertNull($row['reason']);
    }

    #[Test]
    public function it_names_the_connections_that_use_the_driver(): void
    {
        $this->useMysqlConnection();

        $row = $this->driverRow('mysql');

        $this->assertContains('vanguard_health_mysql', $row['connections']);
    }

    #[Test]
    public function a_missing_restore_client_is_reported_as_the_pdo_path_and_as_degraded(): void
    {
        // The August 2026 shape exactly: backups fine, restores through PDO.
        $this->useMysqlConnection();

        config([
            'vanguard.binaries.mysqldump' => $this->presentBinary('mysqldump'),
            'vanguard.binaries.mysql' => $this->absentBinary('mysql'),
        ]);

        $row = $this->driverRow('mysql');

        $this->assertFalse($row['restore']['present']);
        $this->assertSame('pdo', $row['restore']['via']);
        $this->assertSame('client', $row['dump']['via']);

        // Still possible, so not a failure — but not a healthy installation
        // either, and the screen has to say which.
        $this->assertTrue($row['ok']);
        $this->assertTrue($row['degraded']);
        $this->assertNotEmpty($row['reason']);
        $this->assertMatchesRegularExpression('/slower/i', $row['reason']);
    }

    #[Test]
    public function a_missing_dump_client_is_reported_as_the_pdo_path_too(): void
    {
        $this->useMysqlConnection();

        config([
            'vanguard.binaries.mysqldump' => $this->absentBinary('mysqldump'),
            'vanguard.binaries.mysql' => $this->presentBinary('mysql'),
        ]);

        $row = $this->driverRow('mysql');

        $this->assertSame('pdo', $row['dump']['via']);
        $this->assertSame('client', $row['restore']['via']);
        $this->assertTrue($row['degraded']);
    }

    #[Test]
    public function postgres_without_its_clients_is_reported_as_not_working_at_all(): void
    {
        // Postgres has no PDO fallback on either side. Saying 'pdo' here would
        // be a lie of exactly the kind this screen exists to end.
        config([
            'database.connections.vanguard_health_pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'shop',
                'username' => 'postgres',
            ],
            'tenancy.database.central_connection' => 'vanguard_health_pgsql',
            'vanguard.binaries.pg_dump' => $this->absentBinary('pg_dump'),
            'vanguard.binaries.psql' => $this->absentBinary('psql'),
        ]);

        $row = $this->driverRow('pgsql');

        $this->assertNotNull($row);
        $this->assertSame('none', $row['dump']['via']);
        $this->assertSame('none', $row['restore']['via']);
        $this->assertFalse($row['ok']);
        $this->assertNotEmpty($row['reason']);
        $this->assertMatchesRegularExpression('/no PHP fallback|no PDO fallback/i', $row['reason']);
    }

    #[Test]
    public function postgres_with_its_clients_is_reported_as_working(): void
    {
        config([
            'database.connections.vanguard_health_pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'shop',
                'username' => 'postgres',
            ],
            'tenancy.database.central_connection' => 'vanguard_health_pgsql',
            'vanguard.binaries.pg_dump' => $this->presentBinary('pg_dump'),
            'vanguard.binaries.psql' => $this->presentBinary('psql'),
        ]);

        $row = $this->driverRow('pgsql');

        $this->assertSame('client', $row['dump']['via']);
        $this->assertSame('client', $row['restore']['via']);
        $this->assertTrue($row['ok']);
        $this->assertFalse($row['degraded']);
    }

    #[Test]
    public function the_tenant_connection_driver_is_reported_when_tenancy_is_on(): void
    {
        $this->app->instance(TenancyResolver::class, new FakeTenancyResolver);

        config([
            'database.connections.vanguard_health_tenant' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'tenant',
                'username' => 'postgres',
            ],
            'tenancy.database.tenant_connection_name' => 'vanguard_health_tenant',
        ]);

        $row = $this->driverRow('pgsql');

        $this->assertNotNull($row, 'the tenant databases are dumped too, and by a driver of their own');
        $this->assertContains('vanguard_health_tenant', $row['connections']);
    }

    #[Test]
    public function the_sqlite_driver_reports_the_tools_it_actually_uses(): void
    {
        // The default test connection: sqlite, dumped and restored with gzip.
        $row = $this->driverRow('sqlite');

        $this->assertNotNull($row);
        $this->assertSame('gzip', $row['dump']['client']);
        $this->assertSame('gunzip', $row['restore']['client']);
    }

    #[Test]
    public function an_unknown_connection_does_not_take_the_health_screen_down(): void
    {
        config([
            'tenancy.database.central_connection' => 'not-a-connection',
        ]);

        $this->getJson('/vanguard/api/health')->assertOk()->assertJsonStructure(['database_clients']);
    }
}
