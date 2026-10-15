<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * Against a real PostgreSQL server: a table under FORCE ROW LEVEL SECURITY,
 * an application role that only sees one tenant's rows, and a BYPASSRLS,
 * read-only backup role.
 *
 * Skipped unless a superuser connection is provided, e.g. a throwaway
 * server started locally:
 *
 *   VANGUARD_PG_TEST_HOST=127.0.0.1 VANGUARD_PG_TEST_PORT=5432 \
 *   VANGUARD_PG_TEST_USER=postgres VANGUARD_PG_TEST_PASSWORD=... \
 *   vendor/bin/phpunit --filter PostgresRowLevelSecurityIntegrationTest
 *
 * pg_dump and the server should share a major version. The test creates and
 * drops its own database and roles.
 */
class PostgresRowLevelSecurityIntegrationTest extends TestCase
{
    private const DB = 'vanguard_rls_test';

    private PDO $admin;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('VANGUARD_PG_TEST_HOST')) {
            $this->markTestSkipped('Set VANGUARD_PG_TEST_HOST (and _PORT, _USER, _PASSWORD) to run against a real PostgreSQL server.');
        }

        $this->admin = $this->pdo('postgres', $this->superuser());
        $this->dropFixtures();

        $this->admin->exec('CREATE DATABASE '.self::DB);
        $this->admin->exec("CREATE ROLE vanguard_rls_app LOGIN PASSWORD 'app-pw'");
        $this->admin->exec("CREATE ROLE vanguard_rls_backup LOGIN PASSWORD 'backup-pw' BYPASSRLS");
        $this->admin->exec('ALTER ROLE vanguard_rls_backup SET default_transaction_read_only = on');

        $db = $this->pdo(self::DB, $this->superuser());
        $db->exec('CREATE TABLE records (id int PRIMARY KEY, tenant_id int NOT NULL, label text NOT NULL)');
        $db->exec("INSERT INTO records VALUES (1, 1, 'first-tenant-row'), (2, 2, 'second-tenant-row'), (3, 2, 'second-tenant-other-row')");
        $db->exec('ALTER TABLE records ENABLE ROW LEVEL SECURITY');
        $db->exec('ALTER TABLE records FORCE ROW LEVEL SECURITY');
        $db->exec("CREATE POLICY tenant_isolation ON records USING (tenant_id = coalesce(nullif(current_setting('app.tenant_id', true), ''), '1')::int)");
        $db->exec('GRANT SELECT ON records TO vanguard_rls_app, vanguard_rls_backup');
        $db = null;

        $this->tmp = sys_get_temp_dir().'/vanguard_rls_'.uniqid();
        mkdir($this->tmp, 0700, true);

        config([
            'database.connections.pg_app' => $this->connection('vanguard_rls_app', 'app-pw'),
            'database.connections.pg_backup' => $this->connection('vanguard_rls_backup', 'backup-pw'),
            'tenancy.database.central_connection' => 'pg_app',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->dropFixtures();
        }

        if (isset($this->tmp)) {
            exec('rm -rf '.escapeshellarg($this->tmp));
        }

        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function superuser(): array
    {
        return [(string) getenv('VANGUARD_PG_TEST_USER') ?: 'postgres', (string) getenv('VANGUARD_PG_TEST_PASSWORD')];
    }

    /**
     * @param  array{0: string, 1: string}  $credentials
     */
    private function pdo(string $database, array $credentials): PDO
    {
        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('VANGUARD_PG_TEST_HOST'), getenv('VANGUARD_PG_TEST_PORT') ?: '5432', $database),
            $credentials[0],
            $credentials[1],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(string $username, string $password): array
    {
        return [
            'driver' => 'pgsql',
            'host' => (string) getenv('VANGUARD_PG_TEST_HOST'),
            'port' => (string) (getenv('VANGUARD_PG_TEST_PORT') ?: '5432'),
            'database' => self::DB,
            'username' => $username,
            'password' => $password,
        ];
    }

    private function dropFixtures(): void
    {
        $this->admin->exec('DROP DATABASE IF EXISTS '.self::DB.' WITH (FORCE)');
        $this->admin->exec('DROP ROLE IF EXISTS vanguard_rls_app');
        $this->admin->exec('DROP ROLE IF EXISTS vanguard_rls_backup');
    }

    private function dumpThroughResolver(): string
    {
        $config = app(TenancyResolver::class)->backupDbConfig();
        $destination = $this->tmp.'/dump_'.uniqid().'.sql.gz';

        app(DatabaseDriver::class)->dump('pgsql', $config, $destination);

        return (string) gzdecode((string) file_get_contents($destination));
    }

    #[Test]
    public function the_application_role_cannot_produce_a_complete_dump(): void
    {
        // Sanity: the application role really is filtered.
        $app = $this->pdo(self::DB, ['vanguard_rls_app', 'app-pw']);
        $this->assertSame(1, (int) $app->query('SELECT count(*) FROM records')->fetchColumn());

        $sql = null;

        try {
            $sql = $this->dumpThroughResolver();
        } catch (\RuntimeException $e) {
            // pg_dump runs with row_security = off and refuses a table it
            // cannot read in full: the failure is loud, not a short dump.
            $this->assertStringContainsStringIgnoringCase('row-level security', $e->getMessage());
        }

        if ($sql !== null) {
            $this->assertStringNotContainsString('second-tenant-row', $sql, 'a dump as the application role cannot hold rows it cannot see');
        }
    }

    #[Test]
    public function the_dedicated_backup_role_dumps_every_row(): void
    {
        config(['vanguard.database.backup_connection' => 'pg_backup']);

        $sql = $this->dumpThroughResolver();

        $this->assertStringContainsString('first-tenant-row', $sql);
        $this->assertStringContainsString('second-tenant-row', $sql);
        $this->assertStringContainsString('second-tenant-other-row', $sql);
    }
}
