<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

class RestorePhasesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_announces_each_phase_in_order(): void
    {
        // A restore of a large tenant runs for minutes. Phases are what tell an
        // operator it is progressing; a percentage would have to be invented.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('restore')->once();

        $storage = Mockery::mock(StorageDriver::class);

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('verifyChecksum')->once()->andReturnTrue();
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->andReturnNull();

        $record = $this->makeRecord([
            'type' => 'landlord',
            'file_path' => null,
            'remote_path' => 'backups/landlord.tar',
            'checksum' => str_repeat('a', 64),
        ]);

        $seen = [];

        (new RestoreService($db, $storage, $store))->restore($record, [
            'on_phase' => function (string $phase, array $context = []) use (&$seen) {
                $seen[] = $phase;

                if ($phase === 'downloading') {
                    $this->assertSame('remote', $context['source'] ?? null);
                }
            },
        ]);

        $this->assertSame(['downloading', 'verifying', 'unpacking', 'restoring database'], $seen);
    }

    #[Test]
    public function a_restore_without_a_callback_behaves_exactly_as_before(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $db = Mockery::mock(DatabaseDriver::class);
        $db->shouldReceive('restore')->once();

        $store = Mockery::mock(BackupStorageManager::class);
        $store->shouldReceive('download')->once()->andReturn('/tmp/bundle.tar');
        $store->shouldReceive('unBundle')->once()->andReturn(['database' => '/tmp/db.sql.gz']);
        $store->shouldReceive('cleanTmp')->andReturnNull();

        $record = $this->makeRecord(['type' => 'landlord', 'checksum' => null]);

        $result = (new RestoreService($db, Mockery::mock(StorageDriver::class), $store))
            ->restore($record, ['verify_checksum' => false]);

        $this->assertTrue($result);
    }
}
