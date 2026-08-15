<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The layout contract of a storage archive: what member names it stores, and
 * where extraction puts them back.
 *
 * A backup that cannot be put back where it came from is not a backup, so the
 * assertions here are always about files on disk, never about command strings.
 */
class StorageDriverArchiveLayoutTest extends TestCase
{
    private StorageDriver $driver;
    private string $tmpDir;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver     = new StorageDriver;
        $this->tmpDir     = sys_get_temp_dir().'/vanguard_layout_'.uniqid();
        $this->storageDir = $this->tmpDir.'/storage';

        mkdir($this->storageDir.'/app', 0755, true);
        mkdir($this->storageDir.'/logs', 0755, true);

        // Point the whole application at a throwaway storage tree, so archiving
        // and restoring really move files around without touching the test host.
        $this->app->useStoragePath($this->storageDir);

        config(['vanguard.sources.filesystem_paths' => ['app']]);
        config(['vanguard.sources.filesystem_exclude' => []]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Archive layout
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_stores_members_relative_to_the_storage_path(): void
    {
        file_put_contents($this->storageDir.'/app/photo.jpg', 'binary');
        mkdir($this->storageDir.'/app/public', 0755, true);
        file_put_contents($this->storageDir.'/app/public/logo.png', 'binary');

        $archive = $this->tmpDir.'/storage.tar.gz';

        $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        $members = $this->membersOf($archive);

        $this->assertNotEmpty($members);

        foreach ($members as $member) {
            $this->assertStringStartsWith(
                'app/',
                $member,
                "Member [{$member}] is not stored relative to the storage path.",
            );
        }

        $this->assertContains('app/photo.jpg', $members);
        $this->assertContains('app/public/logo.png', $members);
    }

    #[Test]
    public function it_keeps_a_nested_backup_root_at_its_full_relative_depth(): void
    {
        config(['vanguard.sources.filesystem_paths' => ['app/public']]);

        mkdir($this->storageDir.'/app/public', 0755, true);
        file_put_contents($this->storageDir.'/app/public/logo.png', 'binary');

        $archive = $this->tmpDir.'/nested.tar.gz';

        $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        $this->assertContains('app/public/logo.png', $this->membersOf($archive));
    }

    #[Test]
    public function it_still_creates_an_empty_archive_when_nothing_is_backed_up(): void
    {
        config(['vanguard.sources.filesystem_paths' => ['does-not-exist']]);

        $archive = $this->tmpDir.'/empty.tar.gz';

        $result = $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        $this->assertFileExists($result);
        $this->assertSame([], $this->membersOf($archive));
    }

    // ─────────────────────────────────────────────────────────────
    // Round trip
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_round_trip_puts_every_file_back_at_its_exact_original_location(): void
    {
        file_put_contents($this->storageDir.'/app/photo.jpg', 'photo content');
        mkdir($this->storageDir.'/app/public/deep', 0755, true);
        file_put_contents($this->storageDir.'/app/public/deep/note.txt', 'deep content');

        $archive = $this->tmpDir.'/roundtrip.tar.gz';
        $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        exec('rm -rf '.escapeshellarg($this->storageDir.'/app'));

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('photo content', @file_get_contents($this->storageDir.'/app/photo.jpg'));
        $this->assertSame('deep content', @file_get_contents($this->storageDir.'/app/public/deep/note.txt'));
    }

    #[Test]
    public function a_round_trip_leaves_files_outside_the_archive_scope_untouched(): void
    {
        file_put_contents($this->storageDir.'/app/photo.jpg', 'photo content');
        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $archive = $this->tmpDir.'/scoped.tar.gz';
        $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('log line', @file_get_contents($this->storageDir.'/logs/laravel.log'));
    }

    // ─────────────────────────────────────────────────────────────
    // Excludes
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function excluded_paths_are_really_absent_from_the_archive(): void
    {
        mkdir($this->storageDir.'/app/cache', 0755, true);
        file_put_contents($this->storageDir.'/app/cache/junk.bin', 'junk');
        file_put_contents($this->storageDir.'/app/keep.txt', 'keep me');

        config(['vanguard.sources.filesystem_exclude' => ['app/cache']]);

        $archive = $this->tmpDir.'/excluded.tar.gz';

        $this->driver->archive(
            $this->driver->resolveBackupPaths(),
            $this->driver->resolveExcludePaths(),
            $archive,
        );

        $members = $this->membersOf($archive);

        $this->assertContains('app/keep.txt', $members);
        $this->assertNotContains('app/cache/junk.bin', $members);
    }

    #[Test]
    public function an_excluded_path_never_reappears_on_extraction(): void
    {
        mkdir($this->storageDir.'/app/cache', 0755, true);
        file_put_contents($this->storageDir.'/app/cache/junk.bin', 'junk');
        file_put_contents($this->storageDir.'/app/keep.txt', 'keep me');

        config(['vanguard.sources.filesystem_exclude' => ['app/cache']]);

        $archive = $this->tmpDir.'/excluded2.tar.gz';
        $this->driver->archive(
            $this->driver->resolveBackupPaths(),
            $this->driver->resolveExcludePaths(),
            $archive,
        );

        exec('rm -rf '.escapeshellarg($this->storageDir.'/app'));

        $this->driver->extract($archive, $this->storageDir);

        $this->assertFileExists($this->storageDir.'/app/keep.txt');
        $this->assertFileDoesNotExist($this->storageDir.'/app/cache/junk.bin');
    }

    // ─────────────────────────────────────────────────────────────
    // Backwards compatibility — archives written by the old code
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_restores_a_legacy_archive_holding_absolute_style_members(): void
    {
        $archive = $this->makeLegacyArchive('legacy.tar.gz', ['app']);

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('legacy photo', @file_get_contents($this->storageDir.'/app/photo.jpg'));
        $this->assertSame('legacy note', @file_get_contents($this->storageDir.'/app/public/note.txt'));
    }

    #[Test]
    public function it_restores_a_legacy_archive_produced_on_a_different_machine_layout(): void
    {
        // A production bundle carries /var/www/html/storage/... while a staging
        // one carries /srv/app/current/storage/...: the prefix has to be read
        // off the archive, never assumed.
        $archive = $this->makeLegacyArchive('legacy-deep.tar.gz', ['app'], 'srv/app/current/storage');

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('legacy photo', @file_get_contents($this->storageDir.'/app/photo.jpg'));
    }

    #[Test]
    public function it_restores_a_legacy_archive_covering_several_backup_roots(): void
    {
        $archive = $this->makeLegacyArchive('legacy-multi.tar.gz', ['app', 'framework']);

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('legacy photo', @file_get_contents($this->storageDir.'/app/photo.jpg'));
        $this->assertSame('legacy photo', @file_get_contents($this->storageDir.'/framework/photo.jpg'));
    }

    #[Test]
    public function restoring_a_legacy_archive_leaves_files_outside_its_scope_untouched(): void
    {
        file_put_contents($this->storageDir.'/logs/laravel.log', 'log line');

        $archive = $this->makeLegacyArchive('legacy-scoped.tar.gz', ['app']);

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('log line', @file_get_contents($this->storageDir.'/logs/laravel.log'));

        // No stray copy of the producing machine's absolute path may appear.
        $topLevel = array_values(array_diff(scandir($this->storageDir) ?: [], ['.', '..']));
        sort($topLevel);

        $this->assertSame(['app', 'logs'], $topLevel);
    }

    #[Test]
    public function a_freshly_written_archive_is_never_mistaken_for_a_legacy_one(): void
    {
        // The new format is relative, so a root literally named after the
        // storage directory must still land inside it, not replace it.
        config(['vanguard.sources.filesystem_paths' => ['app']]);

        mkdir($this->storageDir.'/app/storage/inner', 0755, true);
        file_put_contents($this->storageDir.'/app/storage/inner/file.txt', 'inner content');

        $archive = $this->tmpDir.'/lookalike.tar.gz';
        $this->driver->archive($this->driver->resolveBackupPaths(), [], $archive);

        exec('rm -rf '.escapeshellarg($this->storageDir.'/app'));

        $this->driver->extract($archive, $this->storageDir);

        $this->assertSame('inner content', @file_get_contents($this->storageDir.'/app/storage/inner/file.txt'));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Build an archive exactly the way the pre-fix code did: `tar czf <dest>
     * <absolute paths>`, which makes GNU tar strip the leading slash and store
     * the producing machine's whole absolute path as the member name.
     *
     * @param  string         $name    File name of the archive inside the temp dir
     * @param  array<string>  $roots   Backup roots to fake, relative to the fake storage dir
     * @param  string         $prefix  Absolute-ish prefix of the fake producing machine
     * @return string                  Absolute path to the created archive
     */
    private function makeLegacyArchive(string $name, array $roots, string $prefix = 'var/www/html/storage'): string
    {
        $machine = $this->tmpDir.'/legacy-machine/'.$name;
        $base    = $machine.'/'.$prefix;

        $absoluteRoots = [];

        foreach ($roots as $root) {
            mkdir($base.'/'.$root.'/public', 0755, true);
            file_put_contents($base.'/'.$root.'/photo.jpg', 'legacy photo');
            file_put_contents($base.'/'.$root.'/public/note.txt', 'legacy note');
            $absoluteRoots[] = escapeshellarg($base.'/'.$root);
        }

        $archive = $this->tmpDir.'/'.$name;

        exec(sprintf('tar czf %s %s 2>&1', escapeshellarg($archive), implode(' ', $absoluteRoots)), $out, $code);

        $this->assertSame(0, $code, 'Could not build the legacy archive: '.implode("\n", $out));

        return $archive;
    }

    /**
     * Member names held by an archive, normalised without the leading "./".
     *
     * @return array<string>
     */
    private function membersOf(string $archive): array
    {
        exec(sprintf('tar tzf %s 2>&1', escapeshellarg($archive)), $out, $code);

        $this->assertSame(0, $code, 'Could not list the archive: '.implode("\n", $out));

        return collect($out)
            ->map(fn ($m) => ltrim($m, '.'))
            ->map(fn ($m) => ltrim($m, '/'))
            ->reject(fn ($m) => $m === '' || str_ends_with($m, '/'))
            ->values()
            ->all();
    }
}
