<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\VanguardServiceProvider;

/**
 * An unsafe filesystem_paths entry is reported at boot, not at 2am.
 *
 * The read-side guard drops the entry so nothing dangerous is archived; this
 * check exists so the operator learns the configuration is wrong before the
 * backup that silently skips it.
 */
class FilesystemPathsBootValidationTest extends TestCase
{
    #[Test]
    public function it_warns_at_boot_about_an_entry_that_climbs_out_of_storage(): void
    {
        Log::spy();

        config(['vanguard.sources.filesystem_paths' => ['app', '../../etc']]);

        (new VanguardServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'filesystem_paths')
                && ($context['configured'] ?? null) === '../../etc',
        );
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_warns_at_boot_about_an_empty_entry(): void
    {
        Log::spy();

        config(['vanguard.sources.filesystem_paths' => ['']]);

        (new VanguardServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, 'filesystem_paths')
        );
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_says_nothing_about_a_sane_configuration(): void
    {
        Log::spy();

        config(['vanguard.sources.filesystem_paths' => ['app', 'app/public']]);

        (new VanguardServiceProvider($this->app))->boot();

        Log::shouldNotHaveReceived('warning');
        $this->addToAssertionCount(1);
    }
}
