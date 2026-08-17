<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The restore side of the asymmetry that produced the August 2026 incident.
 *
 * The deployed image carried no mysql client, so every backup succeeded
 * through the PDO dump fallback and every restore died with
 * "sh: 1: mysql: not found" — a shelf of archives that could not be restored
 * on the host that wrote them. These tests pin the symmetric fallback:
 * the dump the PDO path writes must be replayable by the PDO path, the archive
 * the CLI path writes must be replayable by it too, and a failure must name
 * the statement that failed.
 */
class DatabaseDriverMysqlPdoRestoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_pdo_restore_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    private function mysqlConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'root',
            'password' => 'secret',
            'charset' => 'utf8mb4',
        ];
    }

    /** The shape the missing client actually takes: a configured path that is not there. */
    private function pinTheClientAtAnAbsentPath(): void
    {
        config()->set('vanguard.binaries.mysql', $this->tmpDir.'/no-such-mysql');
    }

    private function writeStub(string $script): string
    {
        $path = $this->tmpDir.'/stub-'.uniqid();
        file_put_contents($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0755);

        return $path;
    }

    // ─── The fallback triggers, and it triggers automatically ────

    #[Test]
    public function it_restores_through_pdo_when_the_mysql_client_is_missing(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $target = new RestoreTargetPdo;
        $driver = new PdoRestoreDatabaseDriver($target);

        $dump = $this->tmpDir.'/simple.sql.gz';
        file_put_contents($dump, gzencode(
            "SET FOREIGN_KEY_CHECKS=0;\n"
            ."DROP TABLE IF EXISTS `users`;\n"
            ."CREATE TABLE `users` (`id` int NOT NULL, `name` varchar(255) DEFAULT NULL);\n"
            ."INSERT INTO `users` VALUES ('1','ada'),('2','grace');\n"
            ."SET FOREIGN_KEY_CHECKS=1;\n"
        ));

        $driver->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertSame(
            [['id' => 1, 'name' => 'ada'], ['id' => 2, 'name' => 'grace']],
            $target->rows('users'),
        );
    }

    #[Test]
    public function it_still_prefers_the_client_when_it_is_installed(): void
    {
        $received = $this->tmpDir.'/client-stdin.sql';
        config()->set('vanguard.binaries.mysql', $this->writeStub('cat > '.escapeshellarg($received)));

        $target = new RestoreTargetPdo;
        $driver = new PdoRestoreDatabaseDriver($target);

        $dump = $this->tmpDir.'/prefer-client.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE `marker` (`id` int);\n"));

        $driver->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertStringContainsString('CREATE TABLE `marker`', file_get_contents($received));
        $this->assertSame([], $target->executed, 'the PDO path must not run when the client is there');
    }

    #[Test]
    public function the_fallback_announces_itself_in_the_log_and_on_the_console(): void
    {
        // A PDO restore of a large dump is far slower than the client. An
        // operator watching a silent process needs to know why.
        Log::spy();

        $this->pinTheClientAtAnAbsentPath();

        $driver = new PdoRestoreDatabaseDriver(new RestoreTargetPdo);

        $dump = $this->tmpDir.'/announced.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE `t` (`id` int);\n"));

        $driver->restore('mysql', $this->mysqlConfig(), $dump);

        $console = $driver->console->fetch();

        $this->assertStringContainsString('mysql', $console);
        $this->assertMatchesRegularExpression('/slower/i', $console);
        $this->assertStringNotContainsString('secret', $console, 'no credential on the console');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'mysql') && stripos($message, 'slower') !== false)
            ->atLeast()->once();
    }

    // ─── The round trip ──────────────────────────────────────────

    #[Test]
    public function a_dump_written_by_the_pdo_path_is_restored_by_the_pdo_path_with_the_same_data(): void
    {
        // The whole promise, in one test: the archive this host can write is
        // the archive this host can put back.
        $source = new RestoreTargetPdo(seeded: true);
        $target = new RestoreTargetPdo;

        $dumpDriver = new PdoRestoreDatabaseDriver($source);
        $dump = $this->tmpDir.'/roundtrip.sql.gz';
        $dumpDriver->runPdoDump($this->mysqlConfig(), $dump);

        $this->pinTheClientAtAnAbsentPath();
        (new PdoRestoreDatabaseDriver($target))->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertSame($source->rows('users'), $target->rows('users'));
        $this->assertNotEmpty($target->rows('users'));
    }

    #[Test]
    public function the_round_trip_survives_values_a_naive_split_would_cut_in_half(): void
    {
        $source = new RestoreTargetPdo;
        $source->insert(1, 'semi;colon');
        $source->insert(2, "quote's and ; both");
        $source->insert(3, 'back\\slash; and -- a comment');
        $source->insert(4, "line\nbreak; here");
        $source->insert(5, '/* not a comment */; really');
        $source->insert(6, null);

        $dump = $this->tmpDir.'/tricky.sql.gz';
        (new PdoRestoreDatabaseDriver($source))->runPdoDump($this->mysqlConfig(), $dump);

        $target = new RestoreTargetPdo;
        $this->pinTheClientAtAnAbsentPath();
        (new PdoRestoreDatabaseDriver($target))->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertSame($source->rows('users'), $target->rows('users'));
        $this->assertCount(6, $target->rows('users'));
    }

    #[Test]
    public function an_archive_written_by_the_client_path_is_restored_by_the_pdo_path(): void
    {
        // Cross-wise: the shelf holds archives written by mysqldump on a host
        // that had the binary. They must restore on a host that has not.
        $this->pinTheClientAtAnAbsentPath();

        $target = new RestoreTargetPdo;

        $dump = $this->tmpDir.'/mysqldump-shaped.sql.gz';
        file_put_contents($dump, gzencode(
            "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n"
            ."--\n-- Host: db    Database: shop\n-- ------------------------------------------------------\n"
            ."/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
            ."/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
            ."/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            ."DROP TABLE IF EXISTS `users`;\n"
            ."CREATE TABLE `users` (\n"
            ."  `id` int NOT NULL,\n"
            ."  `name` varchar(255) DEFAULT NULL\n"
            .");\n"
            ."LOCK TABLES `users` WRITE;\n"
            // mysqldump escapes with backslashes, and its values carry semicolons.
            ."INSERT INTO `users` VALUES (1,'a;b'),(2,'it\\'s here; really'),(3,NULL);\n"
            ."UNLOCK TABLES;\n"
            ."/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
            ."\n-- Dump completed on 2026-08-16 10:00:00\n"
        ));

        (new PdoRestoreDatabaseDriver($target))->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'a;b'],
                ['id' => 2, 'name' => "it's here; really"],
                ['id' => 3, 'name' => null],
            ],
            $target->rows('users'),
        );
    }

    #[Test]
    public function a_dump_written_by_the_pdo_path_is_fed_whole_to_the_client_path(): void
    {
        // The other direction of the cross: the same archive, replayed by the
        // client, reaches it byte for byte — nothing the PDO writer emits is
        // client-only or PDO-only.
        $source = new RestoreTargetPdo(seeded: true);
        $dump = $this->tmpDir.'/for-the-client.sql.gz';
        (new PdoRestoreDatabaseDriver($source))->runPdoDump($this->mysqlConfig(), $dump);

        $received = $this->tmpDir.'/client-received.sql';
        config()->set('vanguard.binaries.mysql', $this->writeStub('cat > '.escapeshellarg($received)));

        (new PdoRestoreDatabaseDriver(new RestoreTargetPdo))->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertSame(gzdecode(file_get_contents($dump)), file_get_contents($received));
    }

    // ─── Session setup ───────────────────────────────────────────

    #[Test]
    public function it_opens_the_same_session_the_client_path_gets(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $target = new RestoreTargetPdo;
        $driver = new PdoRestoreDatabaseDriver($target);

        $dump = $this->tmpDir.'/session.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE `t` (`id` int);\n"));

        $driver->restore('mysql', $this->mysqlConfig(), $dump);

        $session = implode("\n", $target->executed);

        $this->assertStringContainsString("SET NAMES 'utf8mb4'", $session);
        $this->assertStringContainsString('NO_AUTO_VALUE_ON_ZERO', $session);
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=0', $session);
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=1', $session);

        // Nothing may touch the session time zone: the PDO dump reads
        // TIMESTAMP values in the server's zone, so forcing +00:00 here would
        // shift every one of them on the way back in.
        $this->assertStringNotContainsString('TIME_ZONE', strtoupper($session));

        $this->assertStringContainsString(
            'FOREIGN_KEY_CHECKS=1',
            $target->executed[array_key_last($target->executed)],
            'the checks must be put back last, after the dump has replayed',
        );
    }

    #[Test]
    public function it_puts_the_foreign_key_checks_back_even_when_a_statement_fails(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $target = new RestoreTargetPdo;
        $driver = new PdoRestoreDatabaseDriver($target);

        $dump = $this->tmpDir.'/half-broken.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE `t` (`id` int);\nNOT SQL AT ALL;\n"));

        try {
            $driver->restore('mysql', $this->mysqlConfig(), $dump);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertStringContainsString(
            'FOREIGN_KEY_CHECKS=1',
            $target->executed[array_key_last($target->executed)],
        );
    }

    // ─── Failure reporting ───────────────────────────────────────

    #[Test]
    public function a_failing_statement_is_named_with_the_driver_message(): void
    {
        // "restore failed" is not a diagnosis. The CLI path prints the client's
        // own error; this path must be no vaguer.
        $this->pinTheClientAtAnAbsentPath();

        $driver = new PdoRestoreDatabaseDriver(new RestoreTargetPdo);

        $dump = $this->tmpDir.'/broken.sql.gz';
        file_put_contents($dump, gzencode(
            "CREATE TABLE `t` (`id` int);\n"
            ."INSERT INTO `absent_table` VALUES ('1','x');\n"
        ));

        $exception = null;

        try {
            $driver->restore('mysql', $this->mysqlConfig(), $dump);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('INSERT INTO `absent_table`', $exception->getMessage());
        $this->assertStringContainsString('absent_table', $exception->getMessage());
        $this->assertMatchesRegularExpression('/statement #2/i', $exception->getMessage());
        $this->assertStringContainsString('mysql restore', $exception->getMessage());
    }

    #[Test]
    public function a_huge_failing_statement_is_abbreviated_rather_than_dumped_whole(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $driver = new PdoRestoreDatabaseDriver(new RestoreTargetPdo);

        $dump = $this->tmpDir.'/huge-broken.sql.gz';
        file_put_contents($dump, gzencode(
            'INSERT INTO `absent_table` VALUES '.str_repeat("('x'),", 20000)."('y');\n"
        ));

        $exception = null;

        try {
            $driver->restore('mysql', $this->mysqlConfig(), $dump);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertLessThan(2048, strlen($exception->getMessage()), 'a 100 KB statement must not become a 100 KB log line');
        $this->assertStringContainsString('INSERT INTO `absent_table`', $exception->getMessage());
    }

    #[Test]
    public function an_unreadable_archive_fails_before_anything_is_executed(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $target = new RestoreTargetPdo;
        $driver = new PdoRestoreDatabaseDriver($target);

        $dump = $this->tmpDir.'/corrupt.sql.gz';
        file_put_contents($dump, 'this is not a gzip stream at all');

        $this->expectException(RuntimeException::class);

        try {
            $driver->restore('mysql', $this->mysqlConfig(), $dump);
        } finally {
            $this->assertSame(
                [],
                array_values(array_filter($target->executed, fn ($sql) => ! str_starts_with($sql, 'SET '))),
                'a corrupt archive must not half-restore a database',
            );
        }
    }

    // ─── Credentials ─────────────────────────────────────────────

    #[Test]
    public function the_fallback_puts_no_credential_in_the_environment_or_on_disk(): void
    {
        $this->pinTheClientAtAnAbsentPath();

        $before = glob(sys_get_temp_dir().'/vanguard-mysql-*') ?: [];

        $driver = new PdoRestoreDatabaseDriver(new RestoreTargetPdo);

        $dump = $this->tmpDir.'/credentials.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE `t` (`id` int);\n"));

        $driver->restore('mysql', $this->mysqlConfig(), $dump);

        $this->assertFalse(getenv('MYSQL_PWD'), 'the password must never reach the environment');
        $this->assertSame($before, glob(sys_get_temp_dir().'/vanguard-mysql-*') ?: [], 'no defaults file is needed with no client to feed');
    }
}

/**
 * Runs the PDO restore against a stubbed connection instead of a live server.
 *
 * The fake answers the MySQL-only statements — the SET session statements and,
 * for the dump side, SHOW TABLES / SHOW CREATE TABLE. Everything else is real:
 * the statements the driver splits out of the archive are executed, for real,
 * against a real database, and the rows are read back from it.
 */
class PdoRestoreDatabaseDriver extends DatabaseDriver
{
    public BufferedOutput $console;

    public function __construct(public RestoreTargetPdo $pdo)
    {
        $this->console = new BufferedOutput;
    }

    public function runPdoDump(array $c, string $dest): void
    {
        $this->dumpMysqlViaPdo($c, $dest);
    }

    protected function createMysqlPdo(array $c): \PDO
    {
        return $this->pdo;
    }

    protected function consoleOutput(): OutputInterface
    {
        return $this->console;
    }
}

/**
 * A SQLite-backed PDO standing in for a MySQL connection, on both sides of the
 * round trip: it answers SHOW TABLES / SHOW CREATE TABLE for the dumper and
 * swallows the session and lock statements SQLite has no notion of, while
 * really executing the schema and the data.
 */
class RestoreTargetPdo extends \PDO
{
    /** @var array<int, string> Every statement handed to exec(), in order. */
    public array $executed = [];

    private const SCHEMA = 'CREATE TABLE `users` (`id` int NOT NULL, `name` varchar(255) DEFAULT NULL)';

    public function __construct(bool $seeded = false)
    {
        parent::__construct('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        parent::exec(self::SCHEMA);

        if ($seeded) {
            for ($i = 1; $i <= 5; $i++) {
                $this->insert($i, "user-{$i}");
            }
        }
    }

    public function insert(int $id, ?string $name): void
    {
        $statement = parent::prepare('INSERT INTO `users` (`id`, `name`) VALUES (?, ?)');
        $statement->execute([$id, $name]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $table): array
    {
        return array_map(
            fn (array $row) => ['id' => (int) $row['id'], 'name' => $row['name'] === null ? null : (string) $row['name']],
            parent::query("SELECT * FROM `{$table}` ORDER BY `id`")->fetchAll(),
        );
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        // Leading comments belong to the statement the reader emits — MySQL
        // parses straight through them, SQLite does not always, so they are
        // dropped before deciding what this statement is.
        $bare = preg_replace('/^\s*(?:(?:--[ \t][^\n]*|#[^\n]*)\n\s*)*/', '', $statement) ?? $statement;

        // Session and locking statements MySQL understands and SQLite does not.
        if (preg_match('/^\s*(SET |LOCK TABLES|UNLOCK TABLES|\/\*!)/i', $bare)) {
            return 0;
        }

        return parent::exec($this->translateMysqlEscapes($bare));
    }

    /**
     * Rewrite MySQL's backslash escapes the way SQLite spells them.
     *
     * mysqldump escapes with backslashes and MySQL reads them back; SQLite only
     * knows the doubled quote. This is the fake standing in for the server —
     * the splitting, which is the driver's job, has already happened by here.
     */
    private function translateMysqlEscapes(string $sql): string
    {
        return strtr($sql, [
            '\\\\' => '\\',
            "\\'" => "''",
            '\\"' => '"',
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\Z' => "\x1a",
            '\\0' => "\0",
        ]);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
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
            $create = str_replace('`users`', "`{$m[1]}`", self::SCHEMA);

            return parent::query("SELECT '{$m[1]}' AS \"Table\", '{$create}' AS \"Create Table\"");
        }

        return parent::query($query);
    }
}
