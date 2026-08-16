<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * vendor:publish never overwrites an existing config file, so an upgrade
 * leaves an older config in place. A key the package gained since then falls
 * back to a built-in default — and when that default is "empty", the feature
 * is off without anyone being told. That is how a notifications block nobody
 * published means no alert is ever sent.
 */
class PublishedConfigDriftTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = config_path('vanguard.php');

        if (! is_dir(dirname($this->configPath))) {
            mkdir(dirname($this->configPath), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
        parent::tearDown();
    }

    #[Test]
    public function it_names_the_settings_an_older_published_config_is_missing(): void
    {
        file_put_contents($this->configPath, '<?php return ['
            .'"path" => "vanguard",'
            .'"destinations" => ["local" => ["enabled" => true]],'
            .'];');

        $this->artisan('vanguard:install --no-interaction')
            ->expectsOutputToContain('notifications.mail.to')
            ->expectsOutputToContain('dump.mysql_options')
            ->assertSuccessful();
    }

    #[Test]
    public function it_confirms_a_current_published_config(): void
    {
        copy(__DIR__.'/../../config/vanguard.php', $this->configPath);

        $this->artisan('vanguard:install --no-interaction')
            ->expectsOutputToContain('Published config is up to date.')
            ->assertSuccessful();
    }
}
