<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The read side of filesystem_paths had no traversal guard at all.
 *
 * The destructive side — the wipe that precedes a filesystem restore — already
 * refuses anything that does not sit strictly below storage_path(). The side
 * that decides what goes *into* the archive accepted whatever the config said,
 * so a stray '..' or an empty entry quietly shipped the whole server directory
 * to the backup destination.
 */
class StorageDriverPathGuardTest extends TestCase
{
    protected function driver(): StorageDriver
    {
        return app(StorageDriver::class);
    }

    #[Test]
    public function it_keeps_a_path_that_sits_inside_storage(): void
    {
        $relative = 'vanguard_guard_test';
        @mkdir(storage_path($relative), 0755, true);

        config(['vanguard.sources.filesystem_paths' => [$relative]]);

        $this->assertSame(
            [realpath(storage_path($relative))],
            $this->driver()->resolveBackupPaths(),
        );

        @rmdir(storage_path($relative));
    }

    #[Test]
    public function it_drops_a_path_that_climbs_out_of_storage(): void
    {
        // '..' is not exotic: 'storage/app/../../..' is what a copy-pasted
        // path looks like after somebody moves a directory.
        config(['vanguard.sources.filesystem_paths' => ['../..', 'app/../../..']]);

        $this->assertSame([], $this->driver()->resolveBackupPaths());
    }

    #[Test]
    public function it_drops_an_entry_that_names_the_storage_root_itself(): void
    {
        // '' resolves to storage_path() exactly. It is a directory, so the
        // old filter kept it and the archive became the entire storage tree —
        // caches, sessions, other tenants' files and all.
        config(['vanguard.sources.filesystem_paths' => ['', '.', '/']]);

        $this->assertSame([], $this->driver()->resolveBackupPaths());
    }

    #[Test]
    public function it_says_which_entry_it_refused(): void
    {
        $logger = Log::spy();

        config(['vanguard.sources.filesystem_paths' => ['../..']]);

        $this->assertSame([], $this->driver()->resolveBackupPaths());

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, '[Vanguard]')
                && str_contains($message, 'outside storage_path()')
                && ($context['configured'] ?? null) === '../..',
        );
    }

    #[Test]
    public function a_safe_entry_survives_beside_a_refused_one(): void
    {
        // Refusing the bad entry must not cancel the backup of the good one.
        $relative = 'vanguard_guard_test_two';
        @mkdir(storage_path($relative), 0755, true);

        config(['vanguard.sources.filesystem_paths' => ['../..', $relative]]);

        $this->assertSame(
            [realpath(storage_path($relative))],
            $this->driver()->resolveBackupPaths(),
        );

        @rmdir(storage_path($relative));
    }
}
