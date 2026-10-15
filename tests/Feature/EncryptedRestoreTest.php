<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\Support\FakesAgeBinary;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * Restoring an age-encrypted archive: the private key is handed over at
 * restore time, by the operator, and goes nowhere else.
 */
class EncryptedRestoreTest extends TestCase
{
    use FakesAgeBinary;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_encrypted_restore_'.uniqid();
        mkdir($this->tmpDir.'/storage/app', 0755, true);
        file_put_contents($this->tmpDir.'/storage/app/invoice.pdf', 'original content');
        $this->app->useStoragePath($this->tmpDir.'/storage');

        file_put_contents($this->tmpDir.'/identity.txt', 'fake identity for the fake age');

        config([
            'vanguard.tmp_path' => $this->tmpDir.'/tmp',
            'vanguard.sources.filesystem_paths' => ['app'],
            'vanguard.sources.filesystem_exclude' => [],
            'vanguard.encryption.enabled' => true,
            'vanguard.encryption.recipients' => $this->ageRecipient(),
            'vanguard.binaries.age' => $this->fakeAgeBinary($this->tmpDir),
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    private function encryptedBackup(): BackupRecord
    {
        $record = app(BackupManager::class)->backupFilesystem();

        $this->assertStringEndsWith('.tar.age', $record->file_path);

        // The live file changes after the backup; a restore must bring the
        // backed-up content back.
        file_put_contents($this->tmpDir.'/storage/app/invoice.pdf', 'changed since');

        return $record;
    }

    /**
     * A storage manager that counts downloads.
     */
    private function countingStore(): BackupStorageManager
    {
        return new class extends BackupStorageManager
        {
            public int $downloads = 0;

            public function download(string $storedPath, string $destination = 'local'): string
            {
                $this->downloads++;

                return parent::download($storedPath, $destination);
            }
        };
    }

    private function restoreService(BackupStorageManager $store): RestoreService
    {
        return new RestoreService(
            app(DatabaseDriver::class),
            app(StorageDriver::class),
            $store,
        );
    }

    #[Test]
    public function an_encrypted_backup_without_an_identity_is_refused_before_the_download(): void
    {
        $record = $this->encryptedBackup();
        $store = $this->countingStore();

        try {
            $this->restoreService($store)->restore($record, ['restore_storage' => true]);
            $this->fail('An encrypted archive was restored without a key.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Backup #{$record->id} is age-encrypted: pass --identity=<file>", $e->getMessage());
        }

        $this->assertSame(0, $store->downloads);
        $this->assertSame('changed since', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));
    }

    #[Test]
    public function an_archive_named_tar_age_is_treated_as_encrypted_even_without_meta(): void
    {
        $record = $this->encryptedBackup();
        BackupRecord::whereKey($record->id)->update(['meta' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('age-encrypted');

        $this->restoreService($this->countingStore())->restore($record->fresh(), ['restore_storage' => true]);
    }

    #[Test]
    public function an_encrypted_backup_is_decrypted_then_restored(): void
    {
        $record = $this->encryptedBackup();
        $phases = [];

        $this->restoreService(new BackupStorageManager)->restore($record, [
            'restore_storage' => true,
            'identity_file' => $this->tmpDir.'/identity.txt',
            'on_phase' => function (string $phase) use (&$phases) {
                $phases[] = $phase;
            },
        ]);

        $this->assertSame('original content', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));
        $this->assertSame(['downloading', 'verifying', 'decrypting', 'unpacking', 'restoring files'], $phases);

        // Nothing decrypted is left behind once the restore is over.
        $this->assertSame([], glob($this->tmpDir.'/tmp/vanguard_*') ?: []);
    }

    #[Test]
    public function a_tampered_plaintext_checksum_is_refused(): void
    {
        $record = $this->encryptedBackup();

        $meta = $record->meta;
        $meta['encryption']['plaintext_checksum'] = str_repeat('0', 64);
        $record->meta = $meta;
        $record->save();

        try {
            $this->restoreService(new BackupStorageManager)->restore($record->fresh(), [
                'restore_storage' => true,
                'identity_file' => $this->tmpDir.'/identity.txt',
            ]);
            $this->fail('A decrypted archive that does not match its checksum was restored.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('decrypted archive', $e->getMessage());
        }

        $this->assertSame('changed since', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));
    }

    #[Test]
    public function the_stored_checksum_is_still_verified_before_decryption(): void
    {
        $record = $this->encryptedBackup();
        BackupRecord::whereKey($record->id)->update(['checksum' => str_repeat('f', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum mismatch');

        $this->restoreService(new BackupStorageManager)->restore($record->fresh(), [
            'restore_storage' => true,
            'identity_file' => $this->tmpDir.'/identity.txt',
        ]);
    }

    #[Test]
    public function the_console_restores_with_an_identity_file_and_never_records_it(): void
    {
        $record = $this->encryptedBackup();

        $this->artisan('vanguard:restore', [
            'id' => $record->id,
            '--identity' => $this->tmpDir.'/identity.txt',
            '--restore-storage' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('original content', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));

        $restore = RestoreRecord::latest('id')->first();
        $this->assertSame('completed', $restore->status);

        $row = json_encode($restore->getAttributes());
        $this->assertStringNotContainsString('identity.txt', $row);
        $this->assertStringNotContainsString('fake identity', $row);
    }

    #[Test]
    public function the_console_reads_the_identity_from_a_hidden_prompt_and_erases_its_copy(): void
    {
        $record = $this->encryptedBackup();

        $this->artisan('vanguard:restore', [
            'id' => $record->id,
            '--identity-stdin' => true,
            '--restore-storage' => true,
            '--force' => true,
        ])
            ->expectsQuestion('age identity (the private key, input hidden)', 'fake identity typed in')
            ->assertExitCode(0);

        $this->assertSame('original content', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));
        $this->assertSame([], glob($this->tmpDir.'/tmp/vanguard_*') ?: [], 'the key copy outlived the restore');
        $this->assertStringNotContainsString('typed in', json_encode(RestoreRecord::latest('id')->first()->getAttributes()));
    }

    #[Test]
    public function the_console_refuses_an_encrypted_backup_without_an_identity_before_anything_runs(): void
    {
        $record = $this->encryptedBackup();

        $this->artisan('vanguard:restore', ['id' => $record->id, '--restore-storage' => true])
            ->expectsOutputToContain('--identity')
            ->assertExitCode(1);

        $this->assertSame(0, RestoreRecord::count());
    }

    #[Test]
    public function the_console_refuses_both_identity_options_at_once(): void
    {
        $record = $this->encryptedBackup();

        $this->artisan('vanguard:restore', [
            'id' => $record->id,
            '--identity' => $this->tmpDir.'/identity.txt',
            '--identity-stdin' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, RestoreRecord::count());
    }

    #[Test]
    public function the_console_refuses_an_unreadable_identity_file(): void
    {
        $record = $this->encryptedBackup();

        $this->artisan('vanguard:restore', [
            'id' => $record->id,
            '--identity' => $this->tmpDir.'/missing.txt',
            '--restore-storage' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, RestoreRecord::count());
    }

    #[Test]
    public function a_plaintext_backup_restores_exactly_as_before(): void
    {
        config(['vanguard.encryption.enabled' => false]);

        $record = app(BackupManager::class)->backupFilesystem();
        file_put_contents($this->tmpDir.'/storage/app/invoice.pdf', 'changed since');
        $phases = [];

        $this->restoreService(new BackupStorageManager)->restore($record, [
            'restore_storage' => true,
            'on_phase' => function (string $phase) use (&$phases) {
                $phases[] = $phase;
            },
        ]);

        $this->assertSame('original content', file_get_contents($this->tmpDir.'/storage/app/invoice.pdf'));
        $this->assertSame(['downloading', 'verifying', 'unpacking', 'restoring files'], $phases);
    }
}
