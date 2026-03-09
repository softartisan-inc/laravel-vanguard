<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use RuntimeException;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Tests\TestCase;

class StorageDriverTest extends TestCase
{
    private StorageDriver $driver;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new StorageDriver;
        $this->tmpDir = sys_get_temp_dir().'/vanguard_storage_tests_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Archive
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_creates_a_tar_gz_archive_from_paths(): void
    {
        $src = $this->tmpDir.'/source';
        mkdir($src);
        file_put_contents($src.'/hello.txt', 'hello world');
        file_put_contents($src.'/data.json', json_encode(['key' => 'value']));

        $destination = $this->tmpDir.'/archive.tar.gz';

        $result = $this->driver->archive([$src], [], $destination);

        $this->assertFileExists($result);
        $this->assertGreaterThan(0, filesize($result));
        $this->assertSame($destination, $result);
    }

    /** @test */
    public function it_creates_an_empty_archive_when_no_paths_given(): void
    {
        $destination = $this->tmpDir.'/empty.tar.gz';

        $result = $this->driver->archive([], [], $destination);

        $this->assertFileExists($result);
        $this->assertSame($destination, $result);
    }

    /** @test */
    public function it_creates_an_empty_archive_when_paths_do_not_exist(): void
    {
        $destination = $this->tmpDir.'/empty.tar.gz';

        $result = $this->driver->archive(['/nonexistent/path'], [], $destination);

        $this->assertFileExists($result);
    }

    /** @test */
    public function it_throws_when_archive_destination_parent_does_not_exist(): void
    {
        $destination = $this->tmpDir.'/missing_parent/archive.tar.gz';

        $src = $this->tmpDir.'/source2';
        mkdir($src);
        file_put_contents($src.'/file.txt', 'test');

        $this->expectException(RuntimeException::class);

        $this->driver->archive([$src], [], $destination);
    }

    // ─────────────────────────────────────────────────────────────
    // Extract
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_extracts_a_tar_gz_archive_to_destination(): void
    {
        // Create source and archive
        $src = $this->tmpDir.'/source';
        mkdir($src);
        file_put_contents($src.'/hello.txt', 'hello from archive');

        $archive = $this->tmpDir.'/archive.tar.gz';
        $this->driver->archive([$src], [], $archive);

        // Extract to a different dir
        $dest = $this->tmpDir.'/extracted';

        $this->driver->extract($archive, $dest);

        $this->assertDirectoryExists($dest);

        // Find any .txt file in extracted dir recursively
        $files = glob($dest.'/**/*.txt') ?: glob($dest.'/*.txt') ?: [];
        // Collect all txt files recursively
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest));
        $txtFiles = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'txt') {
                $txtFiles[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($txtFiles, 'Expected at least one .txt file to be extracted');
    }

    /** @test */
    public function it_throws_when_extract_source_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Archive not found/');

        $this->driver->extract('/nonexistent.tar.gz', $this->tmpDir.'/dest');
    }

    /** @test */
    public function it_wipes_destination_before_extract_when_wipe_is_true(): void
    {
        // Create a pre-existing file
        $dest = $this->tmpDir.'/wipe_target';
        mkdir($dest);
        file_put_contents($dest.'/old_file.txt', 'old content');

        // Create an archive with a different file
        $src = $this->tmpDir.'/new_source';
        mkdir($src);
        file_put_contents($src.'/new_file.txt', 'new content');
        $archive = $this->tmpDir.'/new.tar.gz';
        $this->driver->archive([$src], [], $archive);

        $this->driver->extract($archive, $dest, wipe: true);

        // old_file.txt should be gone
        $this->assertFileDoesNotExist($dest.'/old_file.txt');
    }

    // ─────────────────────────────────────────────────────────────
    // Resolve paths from config
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_resolves_backup_paths_from_config(): void
    {
        // storage_path('app') typically resolves to an existing directory
        config(['vanguard.sources.filesystem_paths' => ['app']]);

        $paths = $this->driver->resolveBackupPaths();

        $this->assertIsArray($paths);
        // Only existing directories are returned
        foreach ($paths as $path) {
            $this->assertDirectoryExists($path);
        }
    }

    /** @test */
    public function it_resolves_exclude_paths_from_config(): void
    {
        config(['vanguard.sources.filesystem_exclude' => ['app/public/tmp', 'logs']]);

        $excludes = $this->driver->resolveExcludePaths();

        $this->assertIsArray($excludes);
        $this->assertCount(2, $excludes);
        $this->assertStringContainsString('app/public/tmp', $excludes[0]);
    }

    // ─────────────────────────────────────────────────────────────
    // Archive with exclude
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_excludes_specified_paths_from_archive(): void
    {
        $src = $this->tmpDir.'/source_with_excludes';
        mkdir($src.'/keep', 0755, true);
        mkdir($src.'/exclude_me', 0755, true);

        file_put_contents($src.'/keep/important.txt', 'keep this');
        file_put_contents($src.'/exclude_me/secret.txt', 'exclude this');

        $archive  = $this->tmpDir.'/selective.tar.gz';
        $extract  = $this->tmpDir.'/selective_out';

        $this->driver->archive([$src], [$src.'/exclude_me'], $archive);
        $this->driver->extract($archive, $extract);

        // Find excluded file
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extract));
        $allFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $allFiles[] = $file->getFilename();
            }
        }

        $this->assertContains('important.txt', $allFiles);
        $this->assertNotContains('secret.txt', $allFiles);
    }
}
