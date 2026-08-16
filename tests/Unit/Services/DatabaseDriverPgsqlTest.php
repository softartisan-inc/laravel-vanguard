<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * PostgreSQL behaviour of the database driver. The MySQL path was hardened in
 * 2.0; these are the same guarantees for pgsql, which kept the original
 * shell-pipeline defects.
 */
class DatabaseDriverPgsqlTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_pgsql_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        putenv('PGPASSWORD');
        parent::tearDown();
    }

    private function fakeBinary(string $script): string
    {
        $path = $this->tmpDir.'/fake-'.uniqid();
        file_put_contents($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0755);

        return $path;
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

    #[Test]
    public function it_throws_when_pg_dump_exits_non_zero(): void
    {
        // The dump used to be piped into gzip, so the exit code checked was
        // gzip's: a pg_dump that died still produced a "completed" backup.
        config()->set('vanguard.binaries.pg_dump', $this->fakeBinary(
            'echo "pg_dump: error: connection failed" >&2'."\n".'exit 1'
        ));

        $dest = $this->tmpDir.'/failing.sql.gz';
        $exception = null;

        try {
            (new DatabaseDriver)->dump('pgsql', $this->pgConfig(), $dest);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('connection failed', $exception->getMessage());
        $this->assertFileDoesNotExist($dest, 'A failed dump must not leave a destination file behind.');
    }

    #[Test]
    public function it_never_writes_pg_dump_stderr_into_the_archive(): void
    {
        config()->set('vanguard.binaries.pg_dump', $this->fakeBinary(
            'echo "pg_dump: warning: no matching schemas" >&2'."\n".'echo "CREATE TABLE users (id int);"'
        ));

        $result = (new DatabaseDriver)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/clean.sql.gz');
        $contents = gzdecode(file_get_contents($result));

        $this->assertStringContainsString('CREATE TABLE', $contents);
        $this->assertStringNotContainsString('warning', $contents);
    }

    #[Test]
    public function it_gives_the_password_to_pg_dump(): void
    {
        $log = $this->tmpDir.'/pgdump-env.txt';
        config()->set('vanguard.binaries.pg_dump', $this->fakeBinary(
            'echo "${PGPASSWORD}" > '.escapeshellarg($log)."\n".'echo "-- dump"'
        ));

        (new DatabaseDriver)->dump('pgsql', $this->pgConfig(), $this->tmpDir.'/pw.sql.gz');

        $this->assertSame('s3cret', trim(file_get_contents($log)));
    }

    #[Test]
    public function it_gives_the_password_to_psql_on_restore(): void
    {
        // PGPASSWORD=... gunzip -c file | psql sets the variable for gunzip,
        // not for psql, so psql prompted for a password and the restore died
        // with "password authentication failed".
        $log = $this->tmpDir.'/psql-env.txt';
        config()->set('vanguard.binaries.psql', $this->fakeBinary(
            'echo "${PGPASSWORD}" > '.escapeshellarg($log)."\n".'cat > /dev/null'
        ));

        $dump = $this->tmpDir.'/restore.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE marker (id int);\n"));

        (new DatabaseDriver)->restore('pgsql', $this->pgConfig(), $dump);

        $this->assertSame('s3cret', trim(file_get_contents($log)));
    }

    #[Test]
    public function it_feeds_the_dump_to_psql_on_stdin(): void
    {
        $received = $this->tmpDir.'/psql-stdin.sql';
        config()->set('vanguard.binaries.psql', $this->fakeBinary('cat > '.escapeshellarg($received)));

        $dump = $this->tmpDir.'/payload.sql.gz';
        file_put_contents($dump, gzencode("CREATE TABLE marker (id int);\n"));

        (new DatabaseDriver)->restore('pgsql', $this->pgConfig(), $dump);

        $this->assertStringContainsString('CREATE TABLE marker', file_get_contents($received));
    }

    #[Test]
    public function it_throws_when_psql_exits_non_zero(): void
    {
        config()->set('vanguard.binaries.psql', $this->fakeBinary('exit 2'));

        $dump = $this->tmpDir.'/bad.sql.gz';
        file_put_contents($dump, gzencode("SELECT 1;\n"));

        $this->expectException(RuntimeException::class);

        (new DatabaseDriver)->restore('pgsql', $this->pgConfig(), $dump);
    }
}
