<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Cloudflare R2 as a ready-made, opt-in disk.
 *
 * R2 speaks S3, so it goes through the existing 'remote' destination: with
 * an account (or an endpoint) and a bucket, Vanguard declares an S3 disk
 * aimed at R2 (region auto, path-style). Without them nothing is declared —
 * and a disk the application already declares, R2 or the 's3' one, is never
 * touched.
 */
class R2DiskRegistrationTest extends TestCase
{
    private const R2_ENV = [
        'VANGUARD_R2_ACCOUNT_ID' => '0123456789abcdef0123456789abcdef',
        'VANGUARD_R2_ACCESS_KEY_ID' => 'r2-key',
        'VANGUARD_R2_SECRET_ACCESS_KEY' => 'r2-secret',
        'VANGUARD_R2_BUCKET' => 'backups',
    ];

    protected function setUp(): void
    {
        if ($this->name() === 'the_service_provider_declares_the_disk_from_the_environment') {
            foreach (self::R2_ENV as $name => $value) {
                putenv("{$name}={$value}");
            }
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::R2_ENV) as $name) {
            putenv($name);
        }

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'region' => 'eu-west-3',
            'bucket' => 'app-bucket',
        ]);
    }

    private function r2(array $overrides = []): void
    {
        config(['vanguard.r2' => $overrides + [
            'disk' => 'vanguard-r2',
            'account_id' => '0123456789abcdef0123456789abcdef',
            'access_key_id' => 'r2-key',
            'secret_access_key' => 'r2-secret',
            'bucket' => 'backups',
            'jurisdiction' => null,
            'endpoint' => null,
        ]]);
    }

    #[Test]
    public function without_r2_settings_no_disk_is_declared(): void
    {
        // The shipped defaults: every r2 key empty.
        $this->assertNull(config('vanguard.r2.account_id'));
        $this->assertNull(config('vanguard.r2.bucket'));
        $this->assertSame('vanguard-r2', config('vanguard.r2.disk'));

        $this->assertNull(config('filesystems.disks.vanguard-r2'));
        $this->assertNull(Vanguard::registerR2Disk());
        $this->assertNull(config('filesystems.disks.vanguard-r2'));
    }

    #[Test]
    public function the_service_provider_declares_the_disk_from_the_environment(): void
    {
        // The real path: the variables are in the environment before the
        // application boots (see setUp), and the package config reads them
        // when the provider registers.
        $this->assertSame(
            'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com',
            config('filesystems.disks.vanguard-r2.endpoint'),
        );
        $this->assertSame('r2-key', config('filesystems.disks.vanguard-r2.key'));
        $this->assertSame('app-bucket', config('filesystems.disks.s3.bucket'));
    }

    #[Test]
    public function the_disk_is_an_s3_disk_aimed_at_r2(): void
    {
        $this->r2();

        $this->assertSame('vanguard-r2', Vanguard::registerR2Disk());

        $this->assertSame([
            'driver' => 's3',
            'key' => 'r2-key',
            'secret' => 'r2-secret',
            'region' => 'auto',
            'bucket' => 'backups',
            'endpoint' => 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
            'throw' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ], config('filesystems.disks.vanguard-r2'));
    }

    #[Test]
    public function the_disk_sets_no_acl_and_no_visibility(): void
    {
        // R2 does not implement object ACLs.
        $this->r2();

        $disk = Vanguard::r2DiskConfig();

        $this->assertArrayNotHasKey('visibility', $disk);
        $this->assertArrayNotHasKey('options', $disk);
    }

    #[Test]
    public function the_eu_jurisdiction_has_its_own_endpoint(): void
    {
        $this->r2(['jurisdiction' => 'eu']);

        $this->assertSame(
            'https://0123456789abcdef0123456789abcdef.eu.r2.cloudflarestorage.com',
            Vanguard::r2DiskConfig()['endpoint'],
        );
    }

    #[Test]
    public function an_explicit_endpoint_wins_and_needs_no_account(): void
    {
        $this->r2(['account_id' => null, 'endpoint' => 'http://127.0.0.1:9000/']);

        $this->assertSame('vanguard-r2', Vanguard::registerR2Disk());
        $this->assertSame('http://127.0.0.1:9000', config('filesystems.disks.vanguard-r2.endpoint'));
    }

    #[Test]
    public function an_unknown_jurisdiction_is_refused_rather_than_guessed(): void
    {
        $this->r2(['jurisdiction' => 'mars']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mars');

        Vanguard::r2DiskConfig();
    }

    #[Test]
    public function a_bucket_alone_is_not_enough(): void
    {
        $this->r2(['account_id' => null]);

        $this->assertNull(Vanguard::registerR2Disk());
        $this->assertNull(config('filesystems.disks.vanguard-r2'));
    }

    #[Test]
    public function a_disk_the_application_declares_is_never_overwritten(): void
    {
        $this->r2();
        config(['filesystems.disks.vanguard-r2' => ['driver' => 'local', 'root' => '/srv/backups']]);

        $this->assertNull(Vanguard::registerR2Disk());
        $this->assertSame(['driver' => 'local', 'root' => '/srv/backups'], config('filesystems.disks.vanguard-r2'));
    }

    #[Test]
    public function the_existing_s3_disk_is_left_alone(): void
    {
        $before = config('filesystems.disks.s3');

        $this->r2();
        Vanguard::registerR2Disk();

        $this->assertSame($before, config('filesystems.disks.s3'));
    }

    #[Test]
    public function the_disk_name_is_configurable(): void
    {
        $this->r2(['disk' => 'offsite']);

        $this->assertSame('offsite', Vanguard::registerR2Disk());
        $this->assertSame('s3', config('filesystems.disks.offsite.driver'));
        $this->assertNull(config('filesystems.disks.vanguard-r2'));
    }

    #[Test]
    public function the_r2_disk_is_not_s3_when_named_s3_by_mistake(): void
    {
        // VANGUARD_R2_DISK=s3 with an 's3' disk already declared: still never overwritten.
        $before = config('filesystems.disks.s3');
        $this->r2(['disk' => 's3']);

        $this->assertNull(Vanguard::registerR2Disk());
        $this->assertSame($before, config('filesystems.disks.s3'));
    }

    #[Test]
    public function the_health_screen_names_the_missing_s3_adapter_for_the_r2_disk(): void
    {
        if (class_exists(AwsS3V3Adapter::class)) {
            $this->markTestSkipped('league/flysystem-aws-s3-v3 is installed here.');
        }

        Vanguard::auth(fn ($request) => true);
        $this->r2();
        Vanguard::registerR2Disk();
        config([
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => 'vanguard-r2',
        ]);

        $remote = collect($this->getJson('/vanguard/api/health')->assertOk()->json('destinations'))
            ->firstWhere('name', 'remote');

        $this->assertFalse($remote['writable']);
        $this->assertStringContainsString('composer require league/flysystem-aws-s3-v3', $remote['reason']);
    }

    #[Test]
    public function install_names_the_missing_s3_adapter_for_the_r2_disk(): void
    {
        if (class_exists(AwsS3V3Adapter::class)) {
            $this->markTestSkipped('league/flysystem-aws-s3-v3 is installed here.');
        }

        $this->r2();
        Vanguard::registerR2Disk();
        config([
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => 'vanguard-r2',
        ]);

        $this->artisan('vanguard:install --no-interaction')
            ->doesntExpectOutputToContain('is not declared in config/filesystems.php')
            ->expectsOutputToContain('composer require league/flysystem-aws-s3-v3')
            ->assertSuccessful();
    }

    #[Test]
    public function install_explains_how_to_use_r2(): void
    {
        $this->artisan('vanguard:install --no-interaction')
            ->expectsOutputToContain('Cloudflare R2')
            ->expectsOutputToContain('VANGUARD_R2_BUCKET')
            ->assertSuccessful();
    }
}
