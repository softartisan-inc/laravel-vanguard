<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * In single-database mode a tenant backup is files only, and the dashboard
 * says so where a tenant backup is started. The shell hands the mode to the
 * bundle; the bundle carries the wording.
 */
class DashboardDatabaseModeBundleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Vanguard::auth(fn ($request) => true);
    }

    #[Test]
    public function the_shell_passes_multi_by_default(): void
    {
        $this->get('/vanguard')->assertOk()->assertSee('data-database-mode="multi"', false);
    }

    #[Test]
    public function the_shell_passes_single_when_configured(): void
    {
        config(['vanguard.tenancy.database_mode' => 'single']);

        $this->get('/vanguard')->assertOk()->assertSee('data-database-mode="single"', false);
    }

    #[Test]
    public function the_shipped_bundle_reads_the_mode_and_says_files_only(): void
    {
        $bundle = $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();

        $this->assertStringContainsString('databaseMode', $bundle);
        $this->assertStringContainsString('files only', $bundle);
    }
}
