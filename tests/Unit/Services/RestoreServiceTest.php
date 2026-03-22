<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

class RestoreServiceTest extends TestCase
{
    private MockInterface $db;
    private MockInterface $storage;
    private MockInterface $store;
    private RestoreService $restoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db      = Mockery::mock(DatabaseDriver::class);
        $this->storage = Mockery::mock(StorageDriver::class);
        $this->store   = Mockery::mock(BackupStorageManager::class);

        $this->store->shouldReceive('cleanTmp')->byDefault()->andReturnNull();

        $this->restoreService = new RestoreService(
            $this->db,
            $this->storage,
            $this->store,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Guard clauses
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_when_restoring_a_failed_backup(): void
    {
        $record = $this->makeRecord(['status' => 'failed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot restore a backup with status \[failed\]/');

        $this->restoreService->restore($record);
    }

    /** @test */
    public function it_throws_when_restoring_a_running_backup(): void
    {
        $record = $this->makeRecord(['status' => 'running']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot restore a backup with status \[running\]/');

        $this->restoreService->restore($record);
    }

    /** @test */
    public function it_throws_when_no_file_path_is_set(): void
    {
        $record = $this->makeRecord(['file_path' => null, 'remote_path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No file path available/');

        $this->restoreService->restore($record);
    }

    // ─────────────────────────────────────────────────────────────
    // Checksum verification
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_when_checksum_fails_verification(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => str_repeat('a', 64),
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('verifyChecksum')->once()->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Checksum mismatch/');

        $this->restoreService->restore($record, ['verify_checksum' => true]);
    }

    /** @test */
    public function it_skips_checksum_verification_when_option_is_false(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => str_repeat('a', 64),
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldNotReceive('verifyChecksum');
        $this->store->shouldReceive('unBundle')->once()->andReturn([]);

        $result = $this->restoreService->restore($record, ['verify_checksum' => false, 'restore_db' => false]);

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Landlord restore
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_restores_landlord_database_from_backup(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn([
            'database' => '/tmp/db.sql.gz',
        ]);

        $this->db->shouldReceive('restore')
            ->once()
            ->with('sqlite', Mockery::any(), '/tmp/db.sql.gz');

        $result = $this->restoreService->restore($record, ['verify_checksum' => false, 'restore_db' => true]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_skips_db_restore_when_option_is_false(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);

        $this->db->shouldNotReceive('restore');

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
        ]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_restores_filesystem_when_restore_storage_is_true(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn([
            'storage' => '/tmp/fs.tar.gz',
        ]);

        $this->storage->shouldReceive('extract')
            ->once()
            ->with('/tmp/fs.tar.gz', Mockery::any(), false);

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => true,
        ]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_skips_filesystem_restore_when_restore_storage_is_false(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn(['storage' => '/tmp/fs.tar.gz']);

        $this->storage->shouldNotReceive('extract');

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'restore_storage' => false, // default
        ]);

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Filesystem-only restore
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_restores_filesystem_type_backup(): void
    {
        $record = $this->makeRecord([
            'type'      => 'filesystem',
            'file_path' => 'backups/fs.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/fs_bundle.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn(['storage' => '/tmp/fs.tar.gz']);

        $this->storage->shouldReceive('extract')->once();

        $result = $this->restoreService->restore($record, ['verify_checksum' => false]);

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────
    // Unknown type
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_for_unknown_backup_type(): void
    {
        $record = $this->makeRecord([
            'type'      => 'unknown_type',
            'file_path' => 'backups/mystery.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/mystery.tar');
        $this->store->shouldReceive('unBundle')->once()->andReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown backup type/');

        $this->restoreService->restore($record, ['verify_checksum' => false]);
    }

    // ─────────────────────────────────────────────────────────────
    // source option — local / remote / ftp path selection
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_uses_local_path_by_default(): void
    {
        $record = $this->makeRecord([
            'type'        => 'landlord',
            'file_path'   => 'backups/local.tar',
            'remote_path' => 'backups/remote.tar',
            'ftp_path'    => 'backups/ftp.tar',
            'checksum'    => null,
        ]);

        $this->store->shouldReceive('download')
            ->once()
            ->with('backups/local.tar', 'local')
            ->andReturn('/tmp/local.tar');

        $this->store->shouldReceive('unBundle')->once()->andReturn([]);

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
        ]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_uses_remote_path_when_source_is_remote(): void
    {
        $record = $this->makeRecord([
            'type'        => 'landlord',
            'file_path'   => 'backups/local.tar',
            'remote_path' => 'backups/remote.tar',
            'checksum'    => null,
        ]);

        $this->store->shouldReceive('download')
            ->once()
            ->with('backups/remote.tar', 'remote')
            ->andReturn('/tmp/remote.tar');

        $this->store->shouldReceive('unBundle')->once()->andReturn([]);

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'source'          => 'remote',
        ]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_uses_ftp_path_when_source_is_ftp(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/local.tar',
            'ftp_path'  => 'backups/ftp.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')
            ->once()
            ->with('backups/ftp.tar', 'ftp')
            ->andReturn('/tmp/ftp.tar');

        $this->store->shouldReceive('unBundle')->once()->andReturn([]);

        $result = $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'restore_db'      => false,
            'source'          => 'ftp',
        ]);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_throws_when_ftp_path_is_null_but_source_is_ftp(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/local.tar',
            'ftp_path'  => null,
            'checksum'  => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No file path available.*\[ftp\]/');

        $this->restoreService->restore($record, [
            'verify_checksum' => false,
            'source'          => 'ftp',
        ]);
    }

    /** @test */
    public function it_always_cleans_tmp_even_when_restore_throws(): void
    {
        $record = $this->makeRecord([
            'type'      => 'landlord',
            'file_path' => 'backups/landlord.tar',
            'checksum'  => null,
        ]);

        $this->store->shouldReceive('download')->once()->andReturn('/tmp/landlord.tar');
        $this->store->shouldReceive('unBundle')->once()->andThrow(new RuntimeException('extraction failed'));
        $this->store->shouldReceive('cleanTmp')->once(); // must still be called

        $this->expectException(RuntimeException::class);

        $this->restoreService->restore($record, ['verify_checksum' => false]);
    }
}
