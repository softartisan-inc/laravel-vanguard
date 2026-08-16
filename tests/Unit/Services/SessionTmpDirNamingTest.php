<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The session tmp directory holds a plaintext database dump before it is
 * bundled. Its name was derived from the clock, so it was guessable by anyone
 * able to watch the directory — the mode is 0700, which makes this a low
 * severity, but random bytes cost nothing.
 */
class SessionTmpDirNamingTest extends TestCase
{
    protected function store(): object
    {
        return new class extends BackupStorageManager
        {
            public function makeDir(): string
            {
                return $this->makeSessionTmpDir();
            }
        };
    }

    #[Test]
    public function the_directory_name_is_random_rather_than_derived_from_the_clock(): void
    {
        $dir = $this->store()->makeDir();

        $this->assertMatchesRegularExpression(
            '/^vanguard_[0-9a-f]{32}$/',
            basename($dir),
            'a time-derived name is guessable by anyone who knows roughly when the backup ran',
        );

        @rmdir($dir);
    }

    #[Test]
    public function two_sessions_never_share_a_directory(): void
    {
        $first = $this->store()->makeDir();
        $second = $this->store()->makeDir();

        $this->assertNotSame($first, $second);
        $this->assertDirectoryExists($first);
        $this->assertDirectoryExists($second);

        @rmdir($first);
        @rmdir($second);
    }

    #[Test]
    public function the_directory_is_readable_by_nobody_else(): void
    {
        $dir = $this->store()->makeDir();

        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));

        @rmdir($dir);
    }
}
