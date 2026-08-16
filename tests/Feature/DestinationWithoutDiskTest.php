<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * A destination that is enabled and has no disk.
 *
 * The two halves of the same misconfiguration, which used to be invisible on
 * both. The health screen reported writable=null and reason=null — the shape it
 * uses for "not probed, nothing claimed", which is what a *disabled*
 * destination looks like, so an operator reading the page saw a destination
 * they had switched on and a row saying nothing was wrong with it. Meanwhile
 * the write path passed the value on untouched: a blank one — what
 * VANGUARD_REMOTE_DISK= in a .env produces — is falsy, so Storage::disk('')
 * answers with the application's *default* disk and the archives went somewhere
 * nobody chose; a null one died of a TypeError several frames down, mid-backup,
 * naming an argument instead of the setting that is wrong.
 */
class DestinationWithoutDiskTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');

        $this->tmpBase = sys_get_temp_dir().'/vanguard_nodisk_'.uniqid();
        config(['vanguard.tmp_path' => $this->tmpBase]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpBase));
        parent::tearDown();
    }

    /**
     * @return array<string, string> One component file, ready to bundle
     */
    protected function componentFiles(BackupStorageManager $manager): array
    {
        $path = $manager->tmpPath('landlord_db.sql.gz');
        file_put_contents($path, gzencode("SELECT 1;\n"));

        return ['database' => $path];
    }

    // ─── The health screen says so ───────────────────────────────

    #[Test]
    public function an_enabled_destination_without_a_disk_is_reported_as_a_failure(): void
    {
        config([
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => null,
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $remote = collect($response->json('destinations'))->firstWhere('name', 'remote');

        $this->assertFalse($remote['writable'], 'an enabled destination with no disk is broken, not unprobed');
        $this->assertNotNull($remote['reason']);
        $this->assertStringContainsString('disk', $remote['reason']);
        $this->assertStringContainsString('vanguard.destinations.remote.disk', $remote['reason']);
    }

    #[Test]
    public function a_blank_disk_name_counts_as_no_disk_at_all(): void
    {
        // What VANGUARD_REMOTE_DISK= in a .env file actually produces: the key
        // is present, so the config default never applies, and the value is ''.
        config([
            'vanguard.destinations.ftp.enabled' => true,
            'vanguard.destinations.ftp.disk' => '',
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $ftp = collect($response->json('destinations'))->firstWhere('name', 'ftp');

        $this->assertFalse($ftp['writable']);
        $this->assertStringContainsString('vanguard.destinations.ftp.disk', $ftp['reason']);
    }

    #[Test]
    public function a_disabled_destination_without_a_disk_is_still_merely_unprobed(): void
    {
        // Nothing was switched on, so nothing is claimed either way: this is
        // the one case where writable=null is the honest answer.
        config([
            'vanguard.destinations.remote.enabled' => false,
            'vanguard.destinations.remote.disk' => null,
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $remote = collect($response->json('destinations'))->firstWhere('name', 'remote');

        $this->assertNull($remote['writable']);
        $this->assertNull($remote['reason']);
    }

    // ─── The write path refuses ──────────────────────────────────

    #[Test]
    public function a_backup_refuses_to_write_a_remote_destination_that_has_no_disk(): void
    {
        config([
            'vanguard.destinations.local.enabled' => false,
            'vanguard.destinations.ftp.enabled' => false,
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => null,
        ]);

        $manager = new BackupStorageManager;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/remote.+disk/is');

        $manager->bundle($this->componentFiles($manager), 'landlord_1');
    }

    #[Test]
    public function the_archive_does_not_quietly_land_on_the_application_default_disk(): void
    {
        // The failure that made this worth fixing: Storage::disk('') is not an
        // error, it is the default disk — the manager treats any falsy name as
        // "give me the default". The backup succeeded, the record named a path,
        // and the bytes were somewhere nobody had chosen.
        Storage::fake('the-default');
        config([
            'filesystems.default' => 'the-default',
            'vanguard.destinations.local.enabled' => false,
            'vanguard.destinations.ftp.enabled' => false,
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => '',
            'vanguard.destinations.remote.path' => 'vanguard-backups',
        ]);

        $manager = new BackupStorageManager;

        try {
            $manager->bundle($this->componentFiles($manager), 'landlord_1');
            $this->fail('Expected the bundle to refuse a destination with no disk.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            [],
            Storage::disk('the-default')->allFiles(),
            'the default disk must never receive a backup nobody addressed to it',
        );
    }

    #[Test]
    public function a_backup_refuses_to_write_a_local_destination_that_has_no_disk(): void
    {
        config([
            'vanguard.destinations.remote.enabled' => false,
            'vanguard.destinations.ftp.enabled' => false,
            'vanguard.destinations.local.enabled' => true,
            'vanguard.destinations.local.disk' => null,
        ]);

        $manager = new BackupStorageManager;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/local.+disk/is');

        $manager->bundle($this->componentFiles($manager), 'landlord_1');
    }

    #[Test]
    public function a_backup_refuses_to_write_an_ftp_destination_that_has_no_disk(): void
    {
        config([
            'vanguard.destinations.remote.enabled' => false,
            'vanguard.destinations.local.enabled' => false,
            'vanguard.destinations.ftp.enabled' => true,
            'vanguard.destinations.ftp.disk' => '  ',
        ]);

        $manager = new BackupStorageManager;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ftp.+disk/is');

        $manager->bundle($this->componentFiles($manager), 'landlord_1');
    }

    #[Test]
    public function a_properly_configured_destination_is_still_written(): void
    {
        // The guard must refuse the missing disk and nothing else.
        config([
            'vanguard.destinations.remote.enabled' => false,
            'vanguard.destinations.ftp.enabled' => false,
            'vanguard.destinations.local.enabled' => true,
            'vanguard.destinations.local.disk' => 'local',
            'vanguard.destinations.local.path' => 'vanguard-backups',
        ]);

        $manager = new BackupStorageManager;

        $result = $manager->bundle($this->componentFiles($manager), 'landlord_1');

        $this->assertSame('vanguard-backups/landlord_1.tar', $result['local_path']);
        Storage::disk('local')->assertExists('vanguard-backups/landlord_1.tar');
    }
}
