<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * `vanguard:restore`'s confirmation table said nothing about where the
 * archive it is about to read actually lives. An operator confirming a
 * restore should not have to guess whether the copy about to overwrite their
 * database is the local one or the one in the bucket.
 */
class RestoreDestinationConsoleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function the_confirmation_table_names_the_destinations_holding_the_archive(): void
    {
        $record = $this->makeRecord([
            'status' => 'completed',
            'file_path' => null,
            'remote_path' => 'remote/tenant9.tar',
            'ftp_path' => 'ftp/tenant9.tar',
        ]);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturn(true);
        $this->app->instance(RestoreService::class, $service);

        Artisan::call("vanguard:restore {$record->id} --force");
        $output = Artisan::output();

        // Both destinations that hold this archive are named ...
        $this->assertStringContainsString('remote', $output);
        $this->assertStringContainsString('ftp', $output);

        // ... and the run says which one it is about to read: local was not
        // reached, so remote is the auto-detected source.
        $this->assertMatchesRegularExpression('/Reads from\s*\|\s*remote/', $output);
    }

    #[Test]
    public function the_confirmation_table_is_unmistakable_when_nothing_was_reached(): void
    {
        $record = $this->makeRecord([
            'status' => 'completed',
            'file_path' => null,
            'remote_path' => null,
            'ftp_path' => null,
        ]);

        Artisan::call("vanguard:restore {$record->id} --force --no-db");
        $output = Artisan::output();

        $this->assertStringContainsString('nowhere', $output);
    }
}
