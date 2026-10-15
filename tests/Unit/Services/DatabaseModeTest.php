<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\VanguardServiceProvider;

/**
 * vanguard.tenancy.database_mode: 'multi' (default) or 'single'.
 */
class DatabaseModeTest extends TestCase
{
    #[Test]
    public function the_default_is_multi(): void
    {
        config(['vanguard.tenancy.database_mode' => null]);

        $this->assertSame('multi', (new TenancyResolver)->databaseMode());
        $this->assertTrue(TenancyResolver::databaseModeIsDefault());
    }

    #[Test]
    public function the_shipped_config_defaults_to_multi(): void
    {
        $shipped = require __DIR__.'/../../../config/vanguard.php';

        $this->assertSame('multi', $shipped['tenancy']['database_mode']);
    }

    #[Test]
    public function single_is_read_case_insensitively(): void
    {
        config(['vanguard.tenancy.database_mode' => ' Single ']);

        $resolver = new TenancyResolver;

        $this->assertSame('single', $resolver->databaseMode());
        $this->assertTrue($resolver->sharesOneDatabase());
        $this->assertFalse(TenancyResolver::databaseModeIsDefault());
    }

    #[Test]
    public function an_unknown_mode_is_refused_with_the_key_named(): void
    {
        config(['vanguard.tenancy.database_mode' => 'shared']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vanguard.tenancy.database_mode');

        (new TenancyResolver)->sharesOneDatabase();
    }

    #[Test]
    public function an_unknown_mode_is_reported_at_boot_without_failing_it(): void
    {
        Log::spy();

        config(['vanguard.tenancy.database_mode' => 'shared']);

        (new VanguardServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'vanguard.tenancy.database_mode')
        );
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_known_mode_says_nothing_at_boot(): void
    {
        Log::spy();

        config(['vanguard.tenancy.database_mode' => 'single']);

        (new VanguardServiceProvider($this->app))->boot();

        Log::shouldNotHaveReceived('warning');
        $this->addToAssertionCount(1);
    }
}
