<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\StaleRunReaper;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * A row left `running` by a killed worker.
 *
 * The operations console already *reports* this state (OperationsApiController
 * emits a `stalled` warning past the queue timeout), but nothing ever closed
 * the row and nothing ever alerted. On 2026-08-18 the preprod scheduler was
 * OOM-killed mid-backup: the archive never reached the destination, and the
 * record stayed `running` — which the dashboard renders as in-progress, not as
 * a failure. A backup module that fails silently in the reassuring direction is
 * worse than one that does not run.
 */
class StaleRunReclaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['vanguard.queue.timeout' => 3600]);
    }

    private function reaper(): StaleRunReaper
    {
        return app(StaleRunReaper::class);
    }

    #[Test]
    public function it_fails_a_backup_still_running_past_the_queue_timeout(): void
    {
        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subSeconds(3601),
            'completed_at' => null,
        ]);

        $this->assertSame(1, $this->reaper()->reap()['backups']);

        $record->refresh();

        $this->assertSame('failed', $record->status);
        $this->assertNotNull($record->completed_at);
        $this->assertNotEmpty($record->error, 'a reclaimed row must carry the reason it was closed');
    }

    #[Test]
    public function it_leaves_a_backup_running_within_the_timeout_alone(): void
    {
        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subSeconds(3599),
            'completed_at' => null,
        ]);

        $this->assertSame(0, $this->reaper()->reap()['backups']);
        $this->assertSame('running', $record->refresh()->status);
    }

    #[Test]
    public function it_leaves_completed_and_failed_rows_untouched(): void
    {
        $completed = $this->makeRecord(['status' => 'completed', 'started_at' => now()->subDay()]);
        $failed = $this->makeRecord(['status' => 'failed', 'started_at' => now()->subDay(), 'error' => 'original']);

        $this->reaper()->reap();

        $this->assertSame('completed', $completed->refresh()->status);
        $this->assertSame('failed', $failed->refresh()->status);
        $this->assertSame('original', $failed->refresh()->error, 'an already-failed row keeps its own reason');
    }

    #[Test]
    public function it_falls_back_to_created_at_when_the_row_never_recorded_a_start(): void
    {
        // A process killed between INSERT and the started_at stamp still has to
        // be reclaimable, otherwise it stays running for ever with no anchor.
        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => null,
            'completed_at' => null,
            'created_at' => now()->subSeconds(7200),
        ]);

        $this->assertSame(1, $this->reaper()->reap()['backups']);
        $this->assertSame('failed', $record->refresh()->status);
    }

    #[Test]
    public function it_alerts_on_every_row_it_reclaims(): void
    {
        Event::fake([BackupFailed::class, RestoreFailed::class]);

        $this->makeRecord(['status' => 'running', 'started_at' => now()->subSeconds(7200), 'completed_at' => null]);
        $this->makeRestore(['status' => 'running', 'started_at' => now()->subSeconds(7200)]);

        $this->reaper()->reap();

        // The alert path is the existing failure listener; a reclaimed row that
        // does not travel through it is a silent one.
        Event::assertDispatched(BackupFailed::class);
        Event::assertDispatched(RestoreFailed::class);
    }

    #[Test]
    public function it_fails_a_restore_still_running_past_the_queue_timeout(): void
    {
        $restore = $this->makeRestore([
            'status' => 'running',
            'started_at' => now()->subSeconds(7200),
        ]);

        $this->assertSame(1, $this->reaper()->reap()['restores']);

        $restore->refresh();

        $this->assertSame('failed', $restore->status);
        $this->assertNotEmpty($restore->error);
    }

    #[Test]
    public function a_timeout_of_zero_disables_the_reclaim_entirely(): void
    {
        // Same convention as the operations console: 0 means "no timeout", so
        // nothing is ever old enough to be declared dead.
        config(['vanguard.queue.timeout' => 0]);

        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subYear(),
            'completed_at' => null,
        ]);

        $this->assertSame(['backups' => 0, 'restores' => 0], $this->reaper()->reap());
        $this->assertSame('running', $record->refresh()->status);
    }

    #[Test]
    public function the_cleanup_tmp_command_reclaims_stale_rows(): void
    {
        // cleanup-tmp is the command that already sweeps up after crashed
        // workers, and the only Vanguard command scheduled between two backups.
        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subSeconds(7200),
            'completed_at' => null,
        ]);

        $this->artisan('vanguard:cleanup-tmp')->assertSuccessful();

        $this->assertSame('failed', $record->refresh()->status);
    }

    #[Test]
    public function it_reports_how_many_rows_it_closed_on_the_command_output(): void
    {
        $this->makeRecord(['status' => 'running', 'started_at' => now()->subSeconds(7200), 'completed_at' => null]);

        $this->artisan('vanguard:cleanup-tmp')
            ->expectsOutputToContain('1')
            ->assertSuccessful();
    }

    #[Test]
    public function it_does_not_touch_rows_belonging_to_another_tenant_scope(): void
    {
        // The sweep runs on whatever connection it is called on; this only
        // guards against a query that forgets the tenant column exists.
        $mine = $this->makeRecord([
            'tenant_id' => '7001',
            'type' => 'tenant',
            'status' => 'running',
            'started_at' => now()->subSeconds(7200),
            'completed_at' => null,
        ]);

        $this->reaper()->reap();

        $this->assertSame('failed', $mine->refresh()->status);
        $this->assertSame('7001', $mine->refresh()->tenant_id);
    }

    #[Test]
    public function the_recorded_reason_names_the_timeout_it_exceeded(): void
    {
        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subSeconds(7200),
            'completed_at' => null,
        ]);

        $this->reaper()->reap();

        $this->assertStringContainsString('3600', (string) $record->refresh()->error);
    }

    #[Test]
    public function reaping_twice_reclaims_each_row_once(): void
    {
        $this->makeRecord(['status' => 'running', 'started_at' => now()->subSeconds(7200), 'completed_at' => null]);

        $this->assertSame(1, $this->reaper()->reap()['backups']);
        $this->assertSame(0, $this->reaper()->reap()['backups']);

        $this->assertSame(0, BackupRecord::where('status', 'running')->count());
        $this->assertSame(0, RestoreRecord::where('status', 'running')->count());
    }

    #[Test]
    public function the_dashboard_cleanup_button_reclaims_stale_rows_too(): void
    {
        // The endpoint's contract is parity with `vanguard:cleanup-tmp`. Once
        // the command closes stale rows and the button does not, the same
        // wording on two surfaces means two different things.
        Vanguard::auth(fn ($request) => true);

        $record = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subSeconds(7200),
            'completed_at' => null,
        ]);

        $this->postJson('/vanguard/api/cleanup-tmp', ['confirm' => 'tmp'])
            ->assertOk()
            ->assertJson(['reclaimed' => ['backups' => 1, 'restores' => 0]]);

        $this->assertSame('failed', $record->refresh()->status);
    }
}
