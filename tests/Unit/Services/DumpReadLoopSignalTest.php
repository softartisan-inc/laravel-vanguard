<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * What a signal does to a dump in progress.
 *
 * Every backup runs inside a queue worker, and a queue worker is a process
 * signals are aimed at all day long: Horizon pauses a pool with SIGUSR2 and
 * resumes it with SIGCONT, scales one down with SIGTERM, and Laravel's worker
 * arms a SIGALRM for the job timeout. All four handlers do nothing but set a
 * flag — the job is meant to carry on.
 *
 * The dump loop did not carry on. It waits on stream_select(), and select(2)
 * is never restarted after a signal on Linux, whatever SA_RESTART says: PHP
 * returns false with "Interrupted system call". The loop read that as the end
 * of the stream, closed the pipes under a mysqldump that was still writing,
 * and then reported that dump's exit code — 5, EX_EOF, printed on no stream
 * at all — as though the dump tool had failed. An operator saw
 * "[Vanguard:mysqldump] Command failed (exit 5):" with nothing after the colon
 * and no archive, for a dump that was going perfectly until Horizon rebalanced.
 *
 * The test arms the signal the same way the worker does and asks for the only
 * thing that matters: the whole dump reached the archive.
 */
class DumpReadLoopSignalTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_dump_signal_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        exec('rm -rf '.escapeshellarg($this->tmpDir));

        parent::tearDown();
    }

    #[Test]
    public function a_signal_during_the_dump_does_not_truncate_it(): void
    {
        $marker = '-- vanguard row ';
        $rows = 240;

        $command = [PHP_BINARY, $this->dumpStub($marker, $rows)];
        $dest = $this->tmpDir.'/dump.sql.gz';

        // Exactly how a queue worker takes a signal: asynchronously, through a
        // handler that only records that it happened.
        $received = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use (&$received) {
            $received = true;
        });
        pcntl_alarm(1);

        (new SignalProbeDatabaseDriver)->readIntoGzip($command, $dest, 'mysqldump');

        $this->assertTrue($received, 'The test never signalled itself; it proves nothing.');
        $this->assertFileExists($dest, 'The signal cost us the archive.');

        $dump = gzdecode((string) file_get_contents($dest));

        $this->assertIsString($dump, 'The archive is not a readable gzip stream.');
        $this->assertSame($rows, substr_count($dump, $marker), 'The dump reached the archive short of rows.');
    }

    /**
     * A stand-in for mysqldump, faithful on the two points that matter here.
     *
     * It keeps writing for longer than the alarm takes to fire, so the signal
     * lands mid-dump and not before or after. And it behaves like a client
     * built on libmysqlclient, which sets SIGPIPE to ignore: a closed reader
     * does not kill it, the write merely fails, and it leaves with EX_EOF (5)
     * having printed nothing — the exact silence in the operator's mail.
     *
     * @return string Path to the stub script
     */
    private function dumpStub(string $marker, int $rows): string
    {
        $path = $this->tmpDir.'/dump-stub.php';

        file_put_contents($path, <<<PHP
        <?php
        pcntl_signal(SIGPIPE, SIG_IGN);

        \$failed = false;

        for (\$i = 0; \$i < {$rows}; \$i++) {
            \$line = '{$marker}'.\$i.str_repeat('x', 4096)."\\n";

            if (@fwrite(STDOUT, \$line) === false) {
                \$failed = true;
            }

            usleep(10000);
        }

        if (@fflush(STDOUT) === false) {
            \$failed = true;
        }

        exit(\$failed ? 5 : 0);
        PHP);

        return $path;
    }
}

/**
 * Reaches the protected read loop without going through a live database.
 */
class SignalProbeDatabaseDriver extends DatabaseDriver
{
    /**
     * @param  array<int, string>  $command
     */
    public function readIntoGzip(array $command, string $dest, string $label): void
    {
        $this->runProcessToGzip($command, $dest, $label);
    }
}
