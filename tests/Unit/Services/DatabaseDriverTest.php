<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

class DatabaseDriverTest extends TestCase
{
    private DatabaseDriver $driver;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new DatabaseDriver;
        $this->tmpDir = sys_get_temp_dir().'/vanguard_driver_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Unsupported driver
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_for_unsupported_driver_on_dump(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported DB driver/');

        $this->driver->dump('oracle', [], $this->tmpDir.'/out.sql.gz');
    }

    /** @test */
    public function it_throws_for_unsupported_driver_on_restore(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported DB driver/');

        $this->driver->restore('mssql', [], $this->tmpDir.'/backup.sql.gz');
    }

    // ─────────────────────────────────────────────────────────────
    // Restore: missing file
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_when_restore_source_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Restore file not found/');

        $this->driver->restore('sqlite', ['database' => ':memory:'], '/nonexistent/file.sql.gz');
    }

    // ─────────────────────────────────────────────────────────────
    // SQLite: dump and restore cycle
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_can_dump_and_restore_a_sqlite_database(): void
    {
        // Create a real SQLite file with a table
        $sqliteFile = $this->tmpDir.'/test.sqlite';
        $pdo = new \PDO("sqlite:{$sqliteFile}");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (name) VALUES ('Alice')");
        unset($pdo);

        $destination = $this->tmpDir.'/dump.sql.gz';

        // Dump
        $result = $this->driver->dump('sqlite', ['database' => $sqliteFile], $destination);

        $this->assertFileExists($result);
        $this->assertStringEndsWith('.sql.gz', $result);
        $this->assertGreaterThan(0, filesize($result));

        // Restore to a new file
        $restoredFile = $this->tmpDir.'/restored.sqlite';
        $this->driver->restore('sqlite', ['database' => $restoredFile], $result);

        $this->assertFileExists($restoredFile);

        // Verify data integrity
        $pdo = new \PDO("sqlite:{$restoredFile}");
        $row = $pdo->query('SELECT name FROM users LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Alice', $row['name']);
    }

    /** @test */
    public function it_throws_when_sqlite_source_file_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SQLite database file not found/');

        $this->driver->dump('sqlite', ['database' => '/nonexistent/db.sqlite'], $this->tmpDir.'/out.sql.gz');
    }

    // ─────────────────────────────────────────────────────────────
    // Destination extension normalization
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_normalizes_destination_extension_when_sql_is_given(): void
    {
        // Create real SQLite to avoid "file not found"
        $sqliteFile = $this->tmpDir.'/ext_test.sqlite';
        $pdo = new \PDO("sqlite:{$sqliteFile}");
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        unset($pdo);

        // Pass .sql instead of .sql.gz — driver should normalize
        $dest   = $this->tmpDir.'/out.sql';
        $result = $this->driver->dump('sqlite', ['database' => $sqliteFile], $dest);

        $this->assertStringEndsWith('.sql.gz', $result);
        $this->assertFileExists($result);
    }

    /** @test */
    public function it_normalizes_destination_extension_when_no_extension_given(): void
    {
        $sqliteFile = $this->tmpDir.'/ext_test2.sqlite';
        $pdo = new \PDO("sqlite:{$sqliteFile}");
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        unset($pdo);

        $dest   = $this->tmpDir.'/out';
        $result = $this->driver->dump('sqlite', ['database' => $sqliteFile], $dest);

        $this->assertStringEndsWith('.sql.gz', $result);
        $this->assertFileExists($result);
    }

    // ─────────────────────────────────────────────────────────────
    // MySQL args (via reflection — no actual MySQL server needed)
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function mysql_connection_args_include_host_port_and_user(): void
    {
        $reflection = new \ReflectionMethod(DatabaseDriver::class, 'mysqlConnectionArgs');
        $reflection->setAccessible(true);

        $args = $reflection->invoke($this->driver, [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'username' => 'root',
            'password' => '',
        ]);

        $this->assertStringContainsString('127.0.0.1', $args);
        $this->assertStringContainsString('3306', $args);
        $this->assertStringContainsString('root', $args);
        $this->assertStringContainsString('--single-transaction', $args);
    }

    /** @test */
    public function mysql_connection_args_include_socket_when_configured(): void
    {
        $reflection = new \ReflectionMethod(DatabaseDriver::class, 'mysqlConnectionArgs');
        $reflection->setAccessible(true);

        $args = $reflection->invoke($this->driver, [
            'host'        => '127.0.0.1',
            'port'        => 3306,
            'username'    => 'root',
            'unix_socket' => '/var/run/mysql.sock',
        ]);

        $this->assertStringContainsString('--socket=', $args);
        $this->assertStringContainsString('mysql.sock', $args);
    }

    /** @test */
    public function pg_env_returns_empty_string_when_no_password(): void
    {
        $reflection = new \ReflectionMethod(DatabaseDriver::class, 'pgPasswordEnv');
        $reflection->setAccessible(true);

        $env = $reflection->invoke($this->driver, ['password' => '']);

        $this->assertSame('', $env);
    }

    /** @test */
    public function pg_env_returns_pgpassword_when_password_set(): void
    {
        $reflection = new \ReflectionMethod(DatabaseDriver::class, 'pgPasswordEnv');
        $reflection->setAccessible(true);

        $env = $reflection->invoke($this->driver, ['password' => 'secret']);

        $this->assertStringContainsString('PGPASSWORD=', $env);
    }
}
