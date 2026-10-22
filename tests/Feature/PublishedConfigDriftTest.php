<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
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

    /** The opt-in settings 2.7.0 added: absent, nothing changes. */
    private const OPTIONAL_SINCE_2_7 = [
        'tenancy.database_mode',
        'database.backup_connection',
        'database.restore_connection',
        'dump.pgsql_version_check',
        'binaries.age',
        'encryption.enabled',
        'encryption.driver',
        'encryption.recipients',
        'encryption.recipients_file',
        'r2.disk',
        'r2.account_id',
        'r2.access_key_id',
        'r2.secret_access_key',
        'r2.bucket',
        'r2.jurisdiction',
        'r2.endpoint',
    ];

    #[Test]
    public function it_names_the_settings_an_older_published_config_is_missing(): void
    {
        file_put_contents($this->configPath, '<?php return ['
            .'"path" => "vanguard",'
            .'"destinations" => ["local" => ["enabled" => true]],'
            .'];');

        $output = $this->installOutput();
        [$missing, $optional] = $this->sections($output);

        $this->assertStringContainsString('- notifications.mail.to', $missing);
        $this->assertStringContainsString('- dump.mysql_options', $missing);
        $this->assertStringContainsString('vendor:publish --tag=vanguard-config --force', $missing);

        foreach (self::OPTIONAL_SINCE_2_7 as $key) {
            $this->assertStringNotContainsString("- {$key}", $missing, "{$key} is not missing, it is optional");
            $this->assertStringContainsString("- {$key}", $optional);
        }
    }

    #[Test]
    public function a_config_published_before_the_opt_in_settings_has_nothing_missing(): void
    {
        // The shipped config without the 16 opt-in keys: what an installation
        // that published 2.6.x holds.
        $config = require __DIR__.'/../../config/vanguard.php';
        foreach (self::OPTIONAL_SINCE_2_7 as $key) {
            Arr::forget($config, $key);
        }
        foreach (['database', 'encryption', 'r2'] as $section) {
            unset($config[$section]);
        }
        file_put_contents($this->configPath, '<?php return '.var_export($config, true).';');

        $output = $this->installOutput();
        [$missing, $optional] = $this->sections($output);

        $this->assertStringNotContainsString('Missing settings', $output);
        $this->assertStringNotContainsString('--force', $output);
        $this->assertSame('', $missing);
        $this->assertStringContainsString('New optional settings (disabled by default, nothing to do):', $output);

        foreach (self::OPTIONAL_SINCE_2_7 as $key) {
            $this->assertMatchesRegularExpression('/^     - '.preg_quote($key, '/').'( |$)/m', $optional);
        }

        // Nested in a section the published config already has: Laravel's
        // one-level merge never brings it in, so its env variable alone is
        // never read. A whole new section is merged and needs no copy.
        $copyFirst = '(to opt in, copy this key into your config first)';
        $this->assertStringContainsString("- tenancy.database_mode {$copyFirst}", $optional);
        $this->assertStringContainsString("- dump.pgsql_version_check {$copyFirst}", $optional);
        $this->assertStringContainsString("- binaries.age {$copyFirst}", $optional);
        $this->assertMatchesRegularExpression('/^     - encryption\.enabled$/m', $optional);
        $this->assertMatchesRegularExpression('/^     - r2\.bucket$/m', $optional);
    }

    #[Test]
    public function the_empty_filesystem_policy_is_optional_since_its_default_is_the_old_behaviour(): void
    {
        $config = require __DIR__.'/../../config/vanguard.php';
        Arr::forget($config, 'sources.on_empty_filesystem');
        Arr::forget($config, 'notifications.mail.to');
        file_put_contents($this->configPath, '<?php return '.var_export($config, true).';');

        [$missing, $optional] = $this->sections($this->installOutput());

        $this->assertStringContainsString('- notifications.mail.to', $missing);
        $this->assertStringNotContainsString('on_empty_filesystem', $missing);
        $this->assertStringContainsString('- sources.on_empty_filesystem (to opt in, copy this key into your config first)', $optional);
    }

    private function installOutput(): string
    {
        $this->assertSame(0, Artisan::call('vanguard:install', ['--no-interaction' => true]));

        return Artisan::output();
    }

    /**
     * Split the drift report into its "missing" and "optional" sections.
     *
     * @return array{0: string, 1: string}
     */
    private function sections(string $output): array
    {
        $optionalAt = strpos($output, 'New optional settings');
        $missingAt = strpos($output, 'Missing settings');

        $missing = $missingAt === false ? '' : substr($output, $missingAt, ($optionalAt === false ? strlen($output) : $optionalAt) - $missingAt);
        $optional = $optionalAt === false ? '' : substr($output, $optionalAt);

        return [$missing, $optional];
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
