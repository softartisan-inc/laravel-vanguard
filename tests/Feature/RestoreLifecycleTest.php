<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class RestoreLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    #[Test]
    public function a_queued_restore_carries_its_target_and_its_actor(): void
    {
        Queue::fake();
        Vanguard::restoreActor(fn () => 'ops:henoc');

        $backup = $this->makeRecord([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'remote_path' => 'vanguard-backups/tenant_9001.tar',
        ]);

        $restore = RestoreRecord::create([
            'backup_id' => $backup->id,
            'type' => $backup->type,
            'tenant_id' => $backup->tenant_id,
            'backup_created_at' => $backup->created_at,
            'source' => 'remote',
            'restore_db' => true,
            'restore_storage' => false,
            'verify_checksum' => true,
            'status' => 'pending',
            'requested_by' => Vanguard::actor(),
        ]);

        RunRestoreJob::dispatch($restore->id)
            ->onConnection(config('vanguard.queue.connection'))
            ->onQueue(config('vanguard.queue.queue', 'vanguard'));

        Queue::assertPushed(RunRestoreJob::class, fn ($job) => $job->restoreId === $restore->id);

        $this->assertSame('ops:henoc', $restore->requested_by);
        $this->assertSame('9001', $restore->tenant_id);
        $this->assertSame('remote', $restore->source);
    }
}
