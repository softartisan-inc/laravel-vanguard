<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\Support\FakesAgeBinary;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;
use SoftArtisan\Vanguard\VanguardServiceProvider;

/**
 * What the API, the queue, the health screen and the dashboard say about
 * encrypted archives — and that none of it shows when encryption is off.
 */
class EncryptedBackupSurfacesTest extends TestCase
{
    use FakesAgeBinary;

    protected function setUp(): void
    {
        parent::setUp();
        Vanguard::auth(fn ($request) => true);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function encryptedRecord(): BackupRecord
    {
        return $this->makeRecord([
            'file_path' => 'vanguard-backups/landlord_1.tar.age',
            'meta' => ['encryption' => ['format' => 'age', 'recipients' => 2, 'plaintext_checksum' => str_repeat('a', 64)]],
        ]);
    }

    #[Test]
    public function the_list_flags_encrypted_backups_only(): void
    {
        $encrypted = $this->encryptedRecord();
        $plain = $this->makeRecord();

        $rows = collect($this->getJson('/vanguard/api/backups')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$encrypted->id]['encrypted']);
        // Absent rather than false: the payload of a plaintext row is
        // exactly what it was before encryption existed.
        $this->assertArrayNotHasKey('encrypted', $rows[$plain->id]);
    }

    #[Test]
    public function a_queued_restore_of_an_encrypted_backup_fails_explicitly_without_running(): void
    {
        $backup = $this->encryptedRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');

        (new RunRestoreJob($restore->id))->handle($service);

        $restore->refresh();
        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('vanguard:restore '.$backup->id.' --identity=<file>', (string) $restore->error);
    }

    #[Test]
    public function health_has_no_encryption_section_by_default(): void
    {
        $this->assertArrayNotHasKey('encryption', $this->getJson('/vanguard/api/health')->assertOk()->json());
    }

    #[Test]
    public function health_reports_the_binary_and_the_recipients_when_enabled(): void
    {
        $dir = sys_get_temp_dir().'/vanguard_health_age_'.uniqid();
        mkdir($dir, 0700, true);

        try {
            config([
                'vanguard.encryption.enabled' => true,
                'vanguard.encryption.recipients' => $this->ageRecipient('q').','.$this->ageRecipient('p'),
                'vanguard.binaries.age' => $this->fakeAgeBinary($dir),
            ]);

            $section = $this->getJson('/vanguard/api/health')->assertOk()->json('encryption');

            $this->assertTrue($section['binary_present']);
            $this->assertSame(2, $section['recipients']);
            $this->assertTrue($section['ok']);
            // Counted, never listed.
            $this->assertStringNotContainsString($this->ageRecipient('q'), json_encode($section));

            config(['vanguard.binaries.age' => $dir.'/missing']);

            $section = $this->getJson('/vanguard/api/health')->assertOk()->json('encryption');
            $this->assertFalse($section['ok']);
            $this->assertNotEmpty($section['warning']);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    #[Test]
    public function the_shipped_bundle_shows_the_badge_and_the_console_command(): void
    {
        $bundle = $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();

        $this->assertStringContainsString('encrypted', $bundle);
        $this->assertStringContainsString('--identity=', $bundle);
    }

    #[Test]
    public function an_unusable_encryption_setup_is_reported_at_boot_only_when_enabled(): void
    {
        $validate = function (): void {
            $provider = new VanguardServiceProvider($this->app);
            (fn () => $this->validateEncryption())->call($provider);
        };

        Log::spy();
        $validate();
        Log::shouldNotHaveReceived('warning');

        config([
            'vanguard.encryption.enabled' => true,
            'vanguard.encryption.driver' => 'gpg',
        ]);

        $validate();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'driver'))->once();

        // Both spy checks above throw on failure; PHPUnit does not count them.
        $this->addToAssertionCount(2);
    }
}
