<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class OperationsConsoleFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');
    }

    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    #[Test]
    public function an_operator_can_walk_the_whole_console_without_touching_the_cli(): void
    {
        Storage::fake('local');
        Queue::fake();
        VanguardScheduler::heartbeat();

        // 1. The health screen is the landing page and it answers.
        $health = $this->getJson('/vanguard/api/health')->assertOk();
        $this->assertTrue($health->json('schedule.alive'));
        $this->assertTrue($health->json('destinations.0.writable'));

        // 2. A backup is triggered with the source choice the CLI offers.
        $this->postJson('/vanguard/api/backups/run', [
            'type' => 'landlord',
            'include_filesystem' => false,
            'queue' => true,
        ])->assertOk()->assertJson(['queued' => true]);

        // 3. That backup completes (the worker's job, faked here).
        Storage::disk('local')->put('vanguard-backups/landlord.tar', 'ARCHIVE');
        $backup = $this->makeRecord([
            'type' => 'landlord',
            'status' => 'completed',
            'file_path' => 'vanguard-backups/landlord.tar',
            'completed_at' => now(),
        ]);

        // 4. Freshness turns green on its own.
        $landlord = collect($this->getJson('/vanguard/api/health')->json('freshness.targets'))
            ->firstWhere('target', 'landlord');
        $this->assertFalse($landlord['stale']);

        // 5. The archive can be taken off the server.
        $this->assertSame(
            'ARCHIVE',
            $this->get("/vanguard/api/backups/{$backup->id}/download")->streamedContent(),
        );

        // 6. A restore is armed with the typed confirmation and queued.
        $restoreId = $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
        ])->assertStatus(202)->json('restore_id');

        Queue::assertPushed(RunRestoreJob::class);

        // 7. It is readable one row at a time, which is the SSE fallback.
        $this->getJson("/vanguard/api/restores/{$restoreId}")->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by', 'ops@in-immo.app');

        // 8. And it is in the history.
        $this->getJson('/vanguard/api/restores?status=pending')->assertOk()->assertJsonCount(1, 'data');

        // 9. Maintenance is reachable too.
        $this->postJson('/vanguard/api/cleanup-tmp', ['confirm' => 'tmp'])->assertOk();
        $this->postJson('/vanguard/api/prune', ['confirm' => 'all-backups', 'days' => 365])
            ->assertOk()->assertJson(['deleted' => 0]);
    }

    #[Test]
    public function replace_mode_is_the_one_thing_the_console_cannot_do(): void
    {
        // Recorded as a test rather than a comment so nobody "completes the
        // parity" later: replace mode does not destroy what the backup
        // contains, it destroys what the backup does not contain, and there
        // is no way back (spec §2, §7).
        Queue::fake();

        $backup = $this->makeRecord(['type' => 'landlord']);

        $this->postJson("/vanguard/api/backups/{$backup->id}/restore", [
            'confirm' => 'landlord',
            'wipe_storage' => true,
        ])->assertStatus(400);

        Queue::assertNothingPushed();
    }
}
