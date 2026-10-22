<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\Support\FakesAgeBinary;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * Opt-in age encryption of the stored archive, and proof that leaving it off
 * changes nothing.
 */
class EncryptedBackupTest extends TestCase
{
    use FakesAgeBinary;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('s3');
        Storage::fake('ftp');
        Event::fake();

        $this->tmpDir = sys_get_temp_dir().'/vanguard_encrypted_backup_'.uniqid();
        mkdir($this->tmpDir, 0700, true);

        config([
            'vanguard.tmp_path' => $this->tmpDir.'/tmp',
            'vanguard.sources.landlord_database' => true,
            'vanguard.sources.filesystem' => false,
            'vanguard.destinations.local.enabled' => true,
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.ftp.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpDir));

        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    private function manager(?DatabaseDriver $db = null): BackupManager
    {
        if ($db === null) {
            $db = Mockery::mock(DatabaseDriver::class);
            $db->shouldReceive('dump')->andReturnUsing(function (string $driver, array $config, string $destination) {
                file_put_contents($destination, gzencode('CREATE TABLE secrets (id int);'));

                return $destination;
            });
        }

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn(['driver' => 'sqlite', 'database' => ':memory:']);

        return new BackupManager($db, new StorageDriver, new BackupStorageManager, $tenancy);
    }

    private function enableEncryption(): void
    {
        config([
            'vanguard.encryption.enabled' => true,
            'vanguard.encryption.recipients' => $this->ageRecipient('q').','.$this->ageRecipient('p'),
            'vanguard.binaries.age' => $this->fakeAgeBinary($this->tmpDir),
        ]);
    }

    #[Test]
    public function an_encrypted_backup_stores_a_tar_age_on_every_destination(): void
    {
        $this->enableEncryption();

        $record = $this->manager()->backupLandlord();

        $this->assertSame('completed', $record->status);

        foreach (['local' => $record->file_path, 's3' => $record->remote_path, 'ftp' => $record->ftp_path] as $disk => $path) {
            $this->assertStringEndsWith('.tar.age', $path, "on {$disk}");
            Storage::disk($disk)->assertExists($path);
        }

        $stored = Storage::disk('local')->get($record->file_path);

        // Not a tar anyone can read: the ustar magic of a plain archive sits
        // at offset 257, and the dump's own name must not appear in clear.
        $this->assertStringStartsWith('age-encryption.org/v1', $stored);
        $this->assertNotSame('ustar', substr($stored, 257, 5));
        $this->assertStringNotContainsString('_db.sql.gz', $stored);

        // The checksum is that of what is stored, so verification before
        // decryption works unchanged.
        $this->assertSame(hash('sha256', $stored), $record->checksum);
        $this->assertSame(strlen($stored), $record->file_size);

        $encryption = $record->meta['encryption'] ?? null;
        $this->assertSame('age', $encryption['format']);
        $this->assertSame(2, $encryption['recipients']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $encryption['plaintext_checksum']);
        $this->assertNotSame($record->checksum, $encryption['plaintext_checksum']);
    }

    #[Test]
    public function the_plaintext_bundle_does_not_survive_the_encryption(): void
    {
        $this->enableEncryption();

        $store = new class extends BackupStorageManager
        {
            public array $leftovers = [];

            public function cleanTmp(): void
            {
                if ($this->sessionTmpDir !== null) {
                    $this->leftovers = glob($this->sessionTmpDir.'/*.tar') ?: [];
                }

                parent::cleanTmp();
            }
        };

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('dump')->andReturnUsing(function ($driver, $config, $destination) {
            file_put_contents($destination, gzencode('x'));

            return $destination;
        });

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('landlordDbConfig')->andReturn(['driver' => 'sqlite', 'database' => ':memory:']);

        config(['vanguard.destinations.local.enabled' => false]);

        (new BackupManager($db, new StorageDriver, $store, $tenancy))->backupLandlord();

        $this->assertSame([], $store->leftovers);
    }

    #[Test]
    public function encryption_without_the_age_binary_fails_before_anything_is_dumped(): void
    {
        $this->enableEncryption();
        config(['vanguard.binaries.age' => $this->tmpDir.'/no-such-age']);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('dump');

        try {
            $this->manager($db)->backupLandlord();
            $this->fail('A backup that cannot be encrypted was attempted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('age binary', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame([], Storage::disk('ftp')->allFiles());
        $this->assertSame(0, BackupRecord::count());
    }

    #[Test]
    public function encryption_without_a_recipient_fails_before_anything_is_dumped(): void
    {
        $this->enableEncryption();
        config(['vanguard.encryption.recipients' => '']);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('dump');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recipient');

        try {
            $this->manager($db)->backupFilesystem();
        } finally {
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    /**
     * An encryption setup that cannot work: no age binary, or no recipient.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unusableEncryption(): array
    {
        return [
            'age binary missing' => [['vanguard.binaries.age' => '/nonexistent/vanguard-no-such-age'], 'age binary'],
            'no recipient' => [['vanguard.encryption.recipients' => ''], 'no recipient'],
        ];
    }

    /**
     * A manager whose every collaborator refuses to be used: the refusal has
     * to come before anything is dumped, archived, bundled or recorded.
     */
    private function managerThatMustNotRun(TenancyResolver $tenancy): BackupManager
    {
        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldNotReceive('dump');

        $storage = Mockery::mock(StorageDriver::class);
        $storage->shouldNotReceive('archive');

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldNotReceive('tmpPath', 'bundle');
        $store->shouldReceive('cleanTmp')->never();

        return new BackupManager($db, $storage, $store, $tenancy);
    }

    /**
     * @param  array<string, mixed>  $broken
     */
    private function assertRefusedBeforeAnything(callable $backup, array $broken, string $message): void
    {
        $this->enableEncryption();
        config($broken);

        try {
            $backup();
            $this->fail('A backup that cannot be encrypted was attempted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringContainsString('Refusing to write an unencrypted archive', $e->getMessage());
        }

        foreach (['local', 's3', 'ftp'] as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(), "nothing stored on {$disk}");
        }
        $this->assertSame([], glob($this->tmpDir.'/tmp/*') ?: [], 'nothing written to the tmp directory');
        $this->assertSame(0, BackupRecord::count(), 'refused before any record is written');
    }

    #[Test]
    #[DataProvider('unusableEncryption')]
    public function a_single_database_tenant_backup_is_refused_before_its_files_are_archived(array $broken, string $message): void
    {
        config([
            'vanguard.tenancy.database_mode' => 'single',
            'vanguard.sources.filesystem' => true,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('sharesOneDatabase')->andReturn(true);
        $tenancy->shouldNotReceive('runForTenant', 'tenantDbConfig');

        $tenant = new class
        {
            public function getTenantKey(): string
            {
                return 'tenant-a';
            }
        };

        $this->assertRefusedBeforeAnything(
            fn () => $this->managerThatMustNotRun($tenancy)->backupTenant($tenant),
            $broken,
            $message,
        );
    }

    #[Test]
    #[DataProvider('unusableEncryption')]
    public function a_filesystem_backup_is_refused_before_anything_is_archived(array $broken, string $message): void
    {
        config(['vanguard.sources.filesystem' => true]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldNotReceive('landlordDbConfig', 'runForTenant');

        $this->assertRefusedBeforeAnything(
            fn () => $this->managerThatMustNotRun($tenancy)->backupFilesystem(),
            $broken,
            $message,
        );
    }

    #[Test]
    public function a_failing_encryption_fails_the_backup_and_stores_nothing(): void
    {
        $this->enableEncryption();
        config(['vanguard.binaries.age' => $this->fakeAgeBinary($this->tmpDir, 'echo "age: boom" >&2; exit 1')]);

        try {
            $this->manager()->backupLandlord();
            $this->fail('A failed encryption was reported as a success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('age encryption failed', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertSame('failed', BackupRecord::first()->status);
    }

    #[Test]
    public function with_encryption_left_off_the_archive_is_exactly_what_it_was(): void
    {
        // The default configuration: no vanguard.encryption override at all.
        $record = $this->manager()->backupLandlord();

        foreach ([$record->file_path, $record->remote_path, $record->ftp_path] as $path) {
            $this->assertStringEndsWith('.tar', $path);
            $this->assertStringEndsNotWith('.age', $path);
        }

        $stored = Storage::disk('local')->get($record->file_path);
        $this->assertSame('ustar', substr($stored, 257, 5));
        $this->assertSame(hash('sha256', $stored), $record->checksum);
        $this->assertArrayNotHasKey('encryption', (array) $record->meta);
        $this->assertSame([], (array) $record->meta);
    }

    #[Test]
    public function with_encryption_left_off_bundle_returns_the_same_keys_as_before(): void
    {
        file_put_contents($this->tmpDir.'/component_db.sql.gz', gzencode('x'));

        $result = (new BackupStorageManager)->bundle(['database' => $this->tmpDir.'/component_db.sql.gz'], 'landlord_1_x');

        $this->assertSame(['size', 'checksum', 'local_path', 'remote_path', 'ftp_path'], array_keys($result));
    }
}
