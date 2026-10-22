<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A full round trip through the real age binary: key pair generated on the
 * spot, archive encrypted to its public key, restored with its private key.
 *
 * Skipped when age / age-keygen are not installed (apt install age).
 */
class AgeEncryptionIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        exec('command -v age >/dev/null 2>&1 && command -v age-keygen >/dev/null 2>&1', $_, $code);

        if ($code !== 0) {
            $this->markTestSkipped('age and age-keygen are not installed.');
        }

        Storage::fake('local');
        Event::fake();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_age_integration_'.uniqid();
        mkdir($this->tmpDir.'/storage/app', 0755, true);
        $this->app->useStoragePath($this->tmpDir.'/storage');
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: string} Identity file path, public key
     */
    private function keyPair(string $name): array
    {
        $identity = $this->tmpDir."/{$name}.key";
        exec('age-keygen -o '.escapeshellarg($identity).' 2>/dev/null', $_, $code);
        $this->assertSame(0, $code);

        exec('age-keygen -y '.escapeshellarg($identity), $public, $code);
        $this->assertSame(0, $code);

        return [$identity, trim($public[0])];
    }

    #[Test]
    public function an_archive_encrypted_by_age_restores_with_either_recipient_key_and_not_with_another(): void
    {
        [$primary, $primaryPublic] = $this->keyPair('primary');
        [$secondary, $secondaryPublic] = $this->keyPair('secondary');
        [$stranger] = $this->keyPair('stranger');

        file_put_contents($this->tmpDir.'/storage/app/record.csv', "id,name\n1,first\n");

        config([
            'vanguard.tmp_path' => $this->tmpDir.'/tmp',
            'vanguard.sources.filesystem_paths' => ['app'],
            'vanguard.sources.filesystem_exclude' => [],
            'vanguard.encryption.enabled' => true,
            'vanguard.encryption.recipients' => $primaryPublic."\n".$secondaryPublic,
        ]);

        $record = app(BackupManager::class)->backupFilesystem();

        $stored = Storage::disk('local')->get($record->file_path);
        $this->assertStringEndsWith('.tar.age', $record->file_path);
        $this->assertStringStartsWith('age-encryption.org/v1', $stored);
        $this->assertStringNotContainsString('1,first', $stored);
        $this->assertSame(2, $record->meta['encryption']['recipients']);

        // A key that is not a recipient cannot open it, and nothing changes.
        file_put_contents($this->tmpDir.'/storage/app/record.csv', 'changed');

        try {
            app(RestoreService::class)->restore($record, ['restore_storage' => true, 'identity_file' => $stranger]);
            $this->fail('An archive was decrypted with a key that is not one of its recipients.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('age decryption failed', $e->getMessage());
        }

        $this->assertSame('changed', file_get_contents($this->tmpDir.'/storage/app/record.csv'));

        // Either recipient opens it: key rotation, or a second custodian.
        foreach ([$primary, $secondary] as $identity) {
            file_put_contents($this->tmpDir.'/storage/app/record.csv', 'changed');

            app(RestoreService::class)->restore($record, ['restore_storage' => true, 'identity_file' => $identity]);

            $this->assertSame("id,name\n1,first\n", file_get_contents($this->tmpDir.'/storage/app/record.csv'));
        }
    }
}
