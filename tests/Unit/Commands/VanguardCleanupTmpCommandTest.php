<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use SoftArtisan\Vanguard\Tests\TestCase;

class VanguardCleanupTmpCommandTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpBase = sys_get_temp_dir().'/vanguard_cleanup_test_'.uniqid();
        mkdir($this->tmpBase, 0700, true);
        config(['vanguard.tmp_path' => $this->tmpBase]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpBase));
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // No-op cases
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function cleanup_tmp_succeeds_when_no_tmp_directory_exists(): void
    {
        config(['vanguard.tmp_path' => '/nonexistent/path/'.uniqid()]);

        $this->artisan('vanguard:cleanup-tmp')
            ->assertSuccessful()
            ->expectsOutputToContain('nothing to clean');
    }

    /** @test */
    public function cleanup_tmp_does_not_remove_recent_directories(): void
    {
        $recentDir = $this->tmpBase.'/vanguard_recent_'.uniqid();
        mkdir($recentDir, 0700, true);

        // Directory was just created — mtime is now, well within 6h default
        $this->artisan('vanguard:cleanup-tmp --hours=6')
            ->assertSuccessful()
            ->expectsOutputToContain('0');

        $this->assertDirectoryExists($recentDir);
    }

    /** @test */
    public function cleanup_tmp_does_not_touch_non_vanguard_directories(): void
    {
        $foreignDir = $this->tmpBase.'/some_other_app_tmp';
        mkdir($foreignDir, 0700, true);
        // Back-date it so it would be removed if the prefix matched
        touch($foreignDir, time() - 7 * 3600);

        $this->artisan('vanguard:cleanup-tmp --hours=6')
            ->assertSuccessful();

        $this->assertDirectoryExists($foreignDir);
    }

    // ─────────────────────────────────────────────────────────────
    // Removal
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function cleanup_tmp_removes_stale_vanguard_directories(): void
    {
        $staleDir = $this->tmpBase.'/vanguard_stale_'.uniqid();
        mkdir($staleDir, 0700, true);
        touch($staleDir, time() - 7 * 3600); // 7 hours old

        $this->artisan('vanguard:cleanup-tmp --hours=6')
            ->assertSuccessful()
            ->expectsOutputToContain('1');

        $this->assertDirectoryDoesNotExist($staleDir);
    }

    /** @test */
    public function cleanup_tmp_removes_only_directories_older_than_hours_threshold(): void
    {
        $staleDir  = $this->tmpBase.'/vanguard_stale_'.uniqid();
        $freshDir  = $this->tmpBase.'/vanguard_fresh_'.uniqid();

        mkdir($staleDir, 0700, true);
        mkdir($freshDir, 0700, true);

        touch($staleDir, time() - 25 * 3600); // 25 hours old
        // freshDir mtime is now — well within threshold

        $this->artisan('vanguard:cleanup-tmp --hours=24')
            ->assertSuccessful()
            ->expectsOutputToContain('1');

        $this->assertDirectoryDoesNotExist($staleDir);
        $this->assertDirectoryExists($freshDir);
    }

    /** @test */
    public function cleanup_tmp_removes_multiple_stale_directories(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $dir = $this->tmpBase.'/vanguard_stale_'.$i.'_'.uniqid();
            mkdir($dir, 0700, true);
            touch($dir, time() - 10 * 3600);
        }

        $this->artisan('vanguard:cleanup-tmp --hours=6')
            ->assertSuccessful()
            ->expectsOutputToContain('3');
    }

    /** @test */
    public function cleanup_tmp_ignores_regular_files_in_base_directory(): void
    {
        $file = $this->tmpBase.'/vanguard_somefile.txt';
        file_put_contents($file, 'data');
        touch($file, time() - 10 * 3600);

        $this->artisan('vanguard:cleanup-tmp --hours=6')
            ->assertSuccessful()
            ->expectsOutputToContain('0');

        $this->assertFileExists($file);
    }

    /** @test */
    public function cleanup_tmp_hours_option_defaults_to_6(): void
    {
        // A directory 5 hours old should survive with default --hours=6
        $dir = $this->tmpBase.'/vanguard_five_hours_'.uniqid();
        mkdir($dir, 0700, true);
        touch($dir, time() - 5 * 3600);

        $this->artisan('vanguard:cleanup-tmp')
            ->assertSuccessful()
            ->expectsOutputToContain('0');

        $this->assertDirectoryExists($dir);
    }
}
