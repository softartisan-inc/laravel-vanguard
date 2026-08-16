<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * How the MySQL password reaches the client.
 *
 * It used to reach it through MYSQL_PWD in the child's environment, which any
 * process running as the same user can read out of /proc/<pid>/environ for as
 * long as the dump lasts — and which a fatal signal, skipping the cleanup,
 * leaves set for every child started afterwards. The credential now travels in
 * a 0600 temporary defaults file consumed with --defaults-extra-file=, removed
 * in a finally block.
 *
 * These tests pin the property, not the mechanism: nothing the driver starts
 * carries the password in its environment, the file is gone when the call
 * returns whether it succeeded or threw, and while it exists nobody but its
 * owner can read it.
 */
class DatabaseDriverMysqlCredentialTest extends TestCase
{
    /** Distinctive enough that finding it anywhere is a finding, not a coincidence. */
    private const PASSWORD = 's3cr3t-vanguard-4f2a';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_mysql_creds_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    /**
     * Write an executable stub standing in for a mysql client binary.
     */
    private function fakeBinary(string $script): string
    {
        $path = $this->tmpDir.'/fake-client-'.uniqid();
        file_put_contents($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0755);

        return $path;
    }

    /**
     * A stub that reports everything the client learned about the credential:
     * its whole environment, its first argument, and — when that argument names
     * a defaults file — the file's mode and content.
     *
     * @param  string  $extra  Appended verbatim, e.g. an exit code or some stdout
     */
    private function reportingBinary(string $extra = ''): string
    {
        $dir = escapeshellarg($this->tmpDir);

        return $this->fakeBinary(<<<SH
            env > {$dir}/env.log
            printf '%s\\n' "\$@" > {$dir}/argv.log
            f="\${1#--defaults-extra-file=}"
            if [ "\$f" != "\$1" ] && [ -f "\$f" ]; then
              stat -c %a "\$f" > {$dir}/mode.log
              cp "\$f" {$dir}/defaults.copy
              printf '%s' "\$f" > {$dir}/defaults.path
            fi
            {$extra}
            SH);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'root',
            'password' => self::PASSWORD,
        ], $overrides);
    }

    private function dumpFile(): string
    {
        $path = $this->tmpDir.'/payload.sql.gz';
        file_put_contents($path, gzencode("INSERT INTO users VALUES (1);\n"));

        return $path;
    }

    private function log(string $name): string
    {
        $path = $this->tmpDir.'/'.$name;

        $this->assertFileExists($path, "The stub client did not write {$name}.");

        return file_get_contents($path);
    }

    // ─────────────────────────────────────────────────────────────
    // The environment carries nothing
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_dump_never_hands_the_password_to_mysqldump_through_the_environment(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $env = $this->log('env.log');

        $this->assertStringNotContainsString('MYSQL_PWD', $env, 'MYSQL_PWD reached the child process.');
        $this->assertStringNotContainsString(self::PASSWORD, $env, 'The password reached the child environment.');
    }

    #[Test]
    public function the_restore_never_hands_the_password_to_the_mysql_client_through_the_environment(): void
    {
        config()->set('vanguard.binaries.mysql', $this->reportingBinary('cat > /dev/null'));

        (new DatabaseDriver)->restore('mysql', $this->config(), $this->dumpFile());

        $env = $this->log('env.log');

        $this->assertStringNotContainsString('MYSQL_PWD', $env, 'MYSQL_PWD reached the child process.');
        $this->assertStringNotContainsString(self::PASSWORD, $env, 'The password reached the child environment.');
    }

    #[Test]
    public function no_mysql_credential_is_left_in_this_process_environment_either(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $this->assertFalse(getenv('MYSQL_PWD'), 'MYSQL_PWD survived in the worker process.');
    }

    // ─────────────────────────────────────────────────────────────
    // The defaults file: first argument, 0600, and gone afterwards
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_dump_reads_the_credential_from_a_defaults_file_given_as_its_first_argument(): void
    {
        // mysqldump refuses --defaults-extra-file anywhere but first:
        // "unknown variable 'defaults-extra-file=...'", and then dies on the
        // next option it would otherwise have understood.
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $argv = explode("\n", $this->log('argv.log'));

        $this->assertStringStartsWith('--defaults-extra-file=', $argv[0]);
        $this->assertStringContainsString(self::PASSWORD, $this->log('defaults.copy'));
    }

    #[Test]
    public function the_restore_reads_the_credential_from_a_defaults_file_given_as_its_first_argument(): void
    {
        config()->set('vanguard.binaries.mysql', $this->reportingBinary('cat > /dev/null'));

        (new DatabaseDriver)->restore('mysql', $this->config(), $this->dumpFile());

        $argv = explode("\n", $this->log('argv.log'));

        $this->assertStringStartsWith('--defaults-extra-file=', $argv[0]);
        $this->assertStringContainsString(self::PASSWORD, $this->log('defaults.copy'));
    }

    #[Test]
    public function the_defaults_file_is_unreadable_to_anyone_but_its_owner_while_it_exists(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $this->assertSame('600', trim($this->log('mode.log')));
    }

    #[Test]
    public function the_defaults_file_is_gone_once_the_dump_has_returned(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $this->assertFileDoesNotExist(trim($this->log('defaults.path')));
    }

    #[Test]
    public function the_defaults_file_is_gone_even_when_the_dump_fails(): void
    {
        // The credential must not outlive a dump that died: exit 2 with a
        // message on stderr is the access-denied shape, and the shape a
        // half-configured cron produces every night.
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary(
            'echo "mysqldump: Got error: 1045: Access denied" >&2'."\n".'exit 2'
        ));

        try {
            (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');
            $this->fail('Expected the failing dump to throw.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileDoesNotExist(trim($this->log('defaults.path')));
    }

    #[Test]
    public function the_defaults_file_is_gone_even_when_the_restore_fails(): void
    {
        config()->set('vanguard.binaries.mysql', $this->reportingBinary('cat > /dev/null'."\n".'exit 1'));

        try {
            (new DatabaseDriver)->restore('mysql', $this->config(), $this->dumpFile());
            $this->fail('Expected the failing restore to throw.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileDoesNotExist(trim($this->log('defaults.path')));
    }

    #[Test]
    public function a_connection_without_a_password_gets_no_defaults_file_at_all(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(['password' => '']), $this->tmpDir.'/out.sql.gz');

        $this->assertStringNotContainsString('--defaults-extra-file=', $this->log('argv.log'));
        $this->assertFileDoesNotExist($this->tmpDir.'/defaults.path');
    }

    // ─────────────────────────────────────────────────────────────
    // A password the defaults-file syntax would otherwise mangle
    // ─────────────────────────────────────────────────────────────

    /**
     * Everything the ini-style option file treats as syntax, in one string:
     * a leading and a trailing space (stripped from an unquoted value), a #
     * (starts a comment), a double quote and a backslash (the escape
     * characters of a quoted value).
     */
    private const AWKWARD_PASSWORD = ' p@ss #1 "quoted" \\back ';

    #[Test]
    public function an_awkward_password_is_written_quoted_and_escaped(): void
    {
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump(
            'mysql',
            $this->config(['password' => self::AWKWARD_PASSWORD]),
            $this->tmpDir.'/out.sql.gz',
        );

        // The decision, pinned verbatim: the value is wrapped in double quotes
        // — so the #, the spaces and the quotes are data — and the two
        // characters that mean something inside those quotes, the backslash and
        // the double quote, are backslash-escaped.
        $this->assertSame(
            "[client]\npassword=\" p@ss #1 \\\"quoted\\\" \\\\back \"\n",
            $this->log('defaults.copy'),
        );
    }

    #[Test]
    public function the_real_mysql_client_reads_that_password_back_unchanged(): void
    {
        // Not a re-implementation of the option-file parser: the actual client
        // is asked what it understood. --print-defaults makes it echo the
        // options it would have started with, password included.
        $mysqldump = trim(shell_exec('command -v mysqldump 2>/dev/null') ?: '');

        if ($mysqldump === '') {
            $this->markTestSkipped('mysqldump is not installed on this machine.');
        }

        $driver = new ExposedCredentialDatabaseDriver;
        $file = $driver->writeDefaults($this->config(['password' => self::AWKWARD_PASSWORD]));

        try {
            // shell_exec, not exec(): exec() trims each output line, which
            // would silently drop the trailing space this password ends with —
            // the very character an unquoted value would have lost.
            $output = (string) shell_exec(sprintf(
                '%s --defaults-extra-file=%s --print-defaults 2>&1',
                escapeshellcmd($mysqldump),
                escapeshellarg($file),
            ));

            $this->assertStringContainsString(
                '--password='.self::AWKWARD_PASSWORD,
                $output,
                'The client did not read back the password the driver wrote.',
            );
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function the_password_never_appears_on_the_command_line(): void
    {
        // The process list is the other public place a credential must not be:
        // --password=… is visible to every user through ps, which is why the
        // password has always been kept off the argument list.
        config()->set('vanguard.binaries.mysqldump', $this->reportingBinary('echo "CREATE TABLE t (id INT);"'));

        (new DatabaseDriver)->dump('mysql', $this->config(), $this->tmpDir.'/out.sql.gz');

        $this->assertStringNotContainsString(self::PASSWORD, $this->log('argv.log'));
    }
}

/**
 * Exposes the defaults-file writer so the real client can be pointed at its
 * output without a dump running.
 */
class ExposedCredentialDatabaseDriver extends DatabaseDriver
{
    public function writeDefaults(array $c): string
    {
        return $this->writeMysqlDefaultsFile($c);
    }
}
