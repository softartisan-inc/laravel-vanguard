<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\GzipDumpWriter;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * MySQL-specific behaviour of the database driver:
 *   - the CLI dump must fail loudly instead of archiving mysqldump's error output
 *   - the mysqldump options must come from config
 *   - the PDO fallback must read unbuffered and batch its INSERT statements
 */
class DatabaseDriverMysqlTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_mysql_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    /**
     * Write an executable stub that stands in for the mysqldump binary.
     */
    private function fakeMysqldump(string $script): string
    {
        $path = $this->tmpDir.'/fake-mysqldump-'.uniqid();
        file_put_contents($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0755);

        return $path;
    }

    private function mysqlConfig(): array
    {
        return [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'shop',
            'username' => 'root',
            'password' => 'secret',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Defect 1 — a failing mysqldump must not produce a "valid" backup
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_throws_when_mysqldump_exits_non_zero(): void
    {
        config()->set('vanguard.binaries.mysqldump', '/bin/false');

        $dest      = $this->tmpDir.'/failing.sql.gz';
        $exception = null;

        try {
            (new DatabaseDriver)->dump('mysql', $this->mysqlConfig(), $dest);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception,
            'Expected the dump to fail when mysqldump exits non-zero.',
        );
        $this->assertStringContainsString('mysqldump', $exception->getMessage());
        $this->assertFileDoesNotExist($dest, 'A failed dump must not leave a destination file behind.');
    }

    #[Test]
    public function it_reports_mysqldump_stderr_in_the_exception_and_leaves_no_file(): void
    {
        $binary = $this->fakeMysqldump(
            'echo "mysqldump: Got error: 1045: Access denied for user" >&2'."\n".'exit 2'
        );

        config()->set('vanguard.binaries.mysqldump', $binary);

        $dest      = $this->tmpDir.'/denied.sql.gz';
        $exception = null;

        try {
            (new DatabaseDriver)->dump('mysql', $this->mysqlConfig(), $dest);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception,
            'Expected the dump to fail when mysqldump reports an error.',
        );
        $this->assertStringContainsString('Access denied for user', $exception->getMessage());
        $this->assertStringContainsString('exit 2', $exception->getMessage());
        $this->assertFileDoesNotExist($dest);
    }

    #[Test]
    public function it_never_writes_mysqldump_stderr_into_the_archive(): void
    {
        // Succeeds (exit 0) but also emits a warning on stderr, as mysqldump often does.
        $binary = $this->fakeMysqldump(
            'echo "mysqldump: [Warning] Using a password on the command line" >&2'."\n".
            'echo "CREATE TABLE \\`users\\` (id INT);"'
        );

        config()->set('vanguard.binaries.mysqldump', $binary);

        $dest = $this->tmpDir.'/clean.sql.gz';

        $result = (new DatabaseDriver)->dump('mysql', $this->mysqlConfig(), $dest);

        $contents = gzdecode(file_get_contents($result));

        $this->assertStringContainsString('CREATE TABLE', $contents);
        $this->assertStringNotContainsString('[Warning]', $contents);
        $this->assertStringNotContainsString('mysqldump:', $contents);
    }

    #[Test]
    public function it_writes_the_full_mysqldump_output_to_the_archive(): void
    {
        // More output than a single pipe buffer, to prove the whole stream is captured.
        $binary = $this->fakeMysqldump(
            'i=0; while [ $i -lt 2000 ]; do echo "INSERT INTO \\`t\\` VALUES ($i);"; i=$((i+1)); done'
        );

        config()->set('vanguard.binaries.mysqldump', $binary);

        $result = (new DatabaseDriver)->dump('mysql', $this->mysqlConfig(), $this->tmpDir.'/big.sql.gz');

        $contents = gzdecode(file_get_contents($result));

        $this->assertSame(2000, substr_count($contents, 'INSERT INTO'));
        $this->assertStringContainsString('VALUES (1999);', $contents);
    }

    #[Test]
    public function it_does_not_deadlock_when_mysqldump_floods_both_streams(): void
    {
        // Both pipes get far more than one kernel buffer, so a reader that
        // drains one stream before the other would block forever.
        $binary = $this->fakeMysqldump(
            'i=0; while [ $i -lt 4000 ]; do '.
            'echo "mysqldump: [Warning] noisy line $i" >&2; '.
            'echo "INSERT INTO \\`t\\` VALUES ($i);"; '.
            'i=$((i+1)); done'
        );

        config()->set('vanguard.binaries.mysqldump', $binary);

        $result = (new DatabaseDriver)->dump('mysql', $this->mysqlConfig(), $this->tmpDir.'/noisy.sql.gz');

        $contents = gzdecode(file_get_contents($result));

        $this->assertSame(4000, substr_count($contents, 'INSERT INTO'));
        $this->assertStringNotContainsString('[Warning]', $contents);
    }

    // ─────────────────────────────────────────────────────────────
    // Defect 2 — configurable mysqldump options
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_default_mysqldump_options_are_safe_and_complete(): void
    {
        $this->assertSame(
            '--single-transaction --quick --routines --triggers --no-tablespaces',
            config('vanguard.dump.mysql_options'),
        );
    }

    #[Test]
    public function the_built_mysqldump_command_carries_the_default_options(): void
    {
        $driver  = new ExposedDatabaseDriver;
        $command = implode(' ', $driver->buildMysqlDumpCommand('/usr/bin/mysqldump', $this->mysqlConfig()));

        $this->assertStringContainsString('--single-transaction', $command);
        $this->assertStringContainsString('--quick', $command);
        $this->assertStringContainsString('--routines', $command);
        $this->assertStringContainsString('--triggers', $command);
        $this->assertStringContainsString('--no-tablespaces', $command);

        // --events requires the EVENT privilege, which restricted accounts rarely have.
        $this->assertStringNotContainsString('--events', $command);

        // Connection details and the database name are still there.
        $this->assertStringContainsString('127.0.0.1', $command);
        $this->assertStringContainsString('3306', $command);
        $this->assertStringContainsString('root', $command);
        $this->assertStringContainsString('shop', $command);
    }

    #[Test]
    public function custom_mysqldump_options_from_config_are_honoured(): void
    {
        config()->set('vanguard.dump.mysql_options', '--single-transaction --routines --triggers --events');

        $driver  = new ExposedDatabaseDriver;
        $command = implode(' ', $driver->buildMysqlDumpCommand('/usr/bin/mysqldump', $this->mysqlConfig()));

        $this->assertStringContainsString('--events', $command);
        $this->assertStringNotContainsString('--no-tablespaces', $command);
    }

    #[Test]
    public function mysqldump_options_may_also_be_configured_as_an_array(): void
    {
        config()->set('vanguard.dump.mysql_options', ['--single-transaction', '--hex-blob']);

        $driver  = new ExposedDatabaseDriver;
        $command = $driver->buildMysqlDumpCommand('/usr/bin/mysqldump', $this->mysqlConfig());

        $this->assertContains('--hex-blob', $command);
        $this->assertContains('--single-transaction', $command);
    }

    #[Test]
    public function the_socket_is_passed_to_mysqldump_when_configured(): void
    {
        $driver  = new ExposedDatabaseDriver;
        $command = $driver->buildMysqlDumpCommand(
            '/usr/bin/mysqldump',
            $this->mysqlConfig() + ['unix_socket' => '/var/run/mysql.sock'],
        );

        $this->assertContains('--socket=/var/run/mysql.sock', $command);
    }

    // ─────────────────────────────────────────────────────────────
    // Defect 3 — the PDO fallback must stream unbuffered and batch INSERTs
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_pdo_fallback_disables_buffered_queries_for_the_data_read(): void
    {
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(5));
        $dest   = $this->tmpDir.'/pdo-buffering.sql.gz';

        $driver->runPdoDump(['database' => 'shop'], $dest);

        $this->assertContains(
            false,
            $driver->pdo->bufferedQuerySettings,
            'Buffered queries must be turned off before reading table data.',
        );
    }

    #[Test]
    public function the_pdo_fallback_batches_insert_statements(): void
    {
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(250));
        $dest   = $this->tmpDir.'/pdo-batched.sql.gz';

        $driver->runPdoDump(['database' => 'shop'], $dest);

        $contents = gzdecode(file_get_contents($dest));

        // 250 rows batched at 100 rows per statement → 3 INSERT statements.
        $this->assertSame(3, substr_count($contents, 'INSERT INTO `users` VALUES'));
        $this->assertStringContainsString('),(', $contents);
    }

    #[Test]
    public function the_pdo_fallback_keeps_its_schema_and_foreign_key_wrappers(): void
    {
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(3));
        $dest   = $this->tmpDir.'/pdo-schema.sql.gz';

        $driver->runPdoDump(['database' => 'shop'], $dest);

        $contents = gzdecode(file_get_contents($dest));

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', $contents);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1;', $contents);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `users`;', $contents);
        $this->assertStringContainsString('CREATE TABLE `users`', $contents);
    }

    #[Test]
    public function the_pdo_fallback_writes_every_row_and_quotes_nulls(): void
    {
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(4, withNull: true));
        $dest   = $this->tmpDir.'/pdo-rows.sql.gz';

        $driver->runPdoDump(['database' => 'shop'], $dest);

        $contents = gzdecode(file_get_contents($dest));

        // 4 rows, the first one carrying a NULL name.
        $this->assertSame(3, substr_count($contents, "'user-"));
        $this->assertSame(4, substr_count($contents, '),(') + 1);
        $this->assertStringContainsString("('1',NULL)", $contents);
    }

    #[Test]
    public function it_fails_loudly_when_the_dump_cannot_be_written_to_disk(): void
    {
        // /dev/full accepts an open and then refuses every write with ENOSPC,
        // which is a full disk without needing privileges to simulate one.
        // A dump that cannot be written must never be reported as successful.
        $driver = new ExposedDatabaseDriver;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not write|disk full/i');

        $driver->runToGzip(
            ['/bin/sh', '-c', 'printf "SELECT 1;%.0s" $(seq 1 20000)'],
            '/dev/full',
            'mysqldump',
        );
    }

    #[Test]
    public function the_pdo_fallback_fails_loudly_when_the_dump_cannot_be_written_to_disk(): void
    {
        // Same hazard as the CLI path, on the path that actually runs when the
        // mysqldump binary is absent: /dev/full refuses every write with ENOSPC.
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(250));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not write|disk full/i');

        $driver->runPdoDump(['database' => 'shop'], '/dev/full');
    }

    #[Test]
    public function the_in_memory_sqlite_dump_fails_loudly_when_it_cannot_be_written_to_disk(): void
    {
        $driver = new ExposedDatabaseDriver;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not write|disk full/i');

        $driver->runSqliteDump(['database' => ':memory:'], '/dev/full');
    }

    #[Test]
    public function the_dump_writer_throws_on_the_very_write_the_destination_refuses(): void
    {
        // close() is never called here, so only the per-write check can throw:
        // this pins the mid-stream guard rather than the trailer guard.
        $writer = GzipDumpWriter::open('/dev/full', 'mysqldump-pdo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not write|disk full/i');

        // Hex payloads compress poorly on purpose, so zlib really emits bytes
        // mid-stream instead of keeping everything in its internal buffer.
        for ($i = 0; $i < 200; $i++) {
            $writer->write(bin2hex(random_bytes(8192)));
        }
    }

    #[Test]
    public function the_pdo_fallback_produces_a_complete_and_valid_gzip_archive(): void
    {
        $driver = new FakePdoDatabaseDriver(new FakeMysqlPdo(250));
        $dest   = $this->tmpDir.'/pdo-complete.sql.gz';

        $driver->runPdoDump(['database' => 'shop'], $dest);

        $raw = file_get_contents($dest);

        // gzip magic number — the archive really is a gzip stream.
        $this->assertSame("\x1f\x8b", substr($raw, 0, 2));

        $contents = gzdecode($raw);

        $this->assertNotFalse($contents, 'The archive must decompress cleanly.');
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', $contents);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=1;', $contents);
        $this->assertSame(3, substr_count($contents, 'INSERT INTO `users` VALUES'));

        // The gzip trailer carries CRC32 and the uncompressed length; both must
        // match the payload, otherwise the archive is silently truncated.
        $trailer = unpack('Vcrc/Vlength', substr($raw, -8));

        $this->assertSame(crc32($contents), $trailer['crc']);
        $this->assertSame(strlen($contents), $trailer['length']);
    }
}

/**
 * Exposes the protected command builder so it can be asserted on directly
 * rather than through a mock.
 */
class ExposedDatabaseDriver extends DatabaseDriver
{
    public function buildMysqlDumpCommand(string $binary, array $c): array
    {
        return $this->mysqlDumpCommand($binary, $c);
    }

    public function runToGzip(array $command, string $dest, string $label): void
    {
        $this->runProcessToGzip($command, $dest, $label);
    }

    public function runSqliteDump(array $c, string $dest): void
    {
        $this->dumpSqlite($c, $dest);
    }
}

/**
 * Runs the PDO fallback against a stubbed connection instead of a live MySQL server.
 */
class FakePdoDatabaseDriver extends DatabaseDriver
{
    public function __construct(public FakeMysqlPdo $pdo) {}

    public function runPdoDump(array $c, string $dest): void
    {
        $this->dumpMysqlViaPdo($c, $dest);
    }

    protected function createMysqlPdo(array $c): \PDO
    {
        return $this->pdo;
    }
}

/**
 * A SQLite-backed PDO that answers the MySQL-only statements the dumper issues,
 * and records every buffered-query setting it is asked for.
 */
class FakeMysqlPdo extends \PDO
{
    /** @var array<int, bool> */
    public array $bufferedQuerySettings = [];

    public function __construct(int $rows = 3, bool $withNull = false)
    {
        parent::__construct('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        parent::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        for ($i = 1; $i <= $rows; $i++) {
            $name = ($withNull && $i === 1) ? 'NULL' : "'user-{$i}'";
            parent::exec("INSERT INTO users (id, name) VALUES ({$i}, {$name})");
        }
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
            $this->bufferedQuerySettings[] = $value;

            return true;
        }

        return parent::setAttribute($attribute, $value);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($query === 'SHOW TABLES') {
            return parent::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        }

        if (preg_match('/^SHOW CREATE TABLE `(.+)`$/', $query, $m)) {
            $create = "CREATE TABLE `{$m[1]}` (`id` int NOT NULL, `name` varchar(255) DEFAULT NULL)";

            return parent::query("SELECT '{$m[1]}' AS \"Table\", '{$create}' AS \"Create Table\"");
        }

        return parent::query($query);
    }
}
