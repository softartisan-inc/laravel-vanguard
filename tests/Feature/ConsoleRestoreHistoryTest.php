<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A restore run from the console, in the history.
 *
 * `vanguard:restore` used to call RestoreService directly: no row, no event,
 * no notification. A restore performed over SSH — the very path an operator
 * takes during an incident, when the dashboard is the thing that is down —
 * left nothing behind at all. Whether it succeeded, whether it failed, who
 * ran it and against which target were answerable only from that operator's
 * terminal scrollback.
 *
 * These assertions hold the console to the same contract as the endpoint: one
 * row per attempt, resolved either way, carrying the error verbatim when there
 * is one, and firing the events the notifications hang off.
 */
class ConsoleRestoreHistoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function a_console_restore_leaves_a_completed_row(): void
    {
        $backup = $this->makeRecord(['tenant_id' => 'acme', 'type' => 'tenant']);

        $this->restoreServiceSucceeds();

        $this->assertSame(0, Artisan::call("vanguard:restore {$backup->id} --force"));

        $restore = RestoreRecord::latest('id')->first();

        $this->assertNotNull($restore, 'a console restore left no trace in the history');
        $this->assertSame('completed', $restore->status);
        $this->assertSame($backup->id, $restore->backup_id);
        $this->assertSame('acme', $restore->tenant_id);
        $this->assertSame('tenant', $restore->type);
        $this->assertNotNull($restore->started_at);
        $this->assertNotNull($restore->completed_at);
    }

    #[Test]
    public function a_failed_console_restore_leaves_a_failed_row_carrying_the_error(): void
    {
        $backup = $this->makeRecord();

        $this->restoreServiceThrows('Checksum mismatch for backup #1.');

        $this->assertSame(1, Artisan::call("vanguard:restore {$backup->id} --force"));

        $restore = RestoreRecord::latest('id')->first();

        $this->assertNotNull($restore, 'a failed console restore left no trace in the history');
        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('Checksum mismatch', (string) $restore->error);
        $this->assertNotNull($restore->completed_at);
    }

    #[Test]
    public function the_row_names_the_operator_who_ran_the_command(): void
    {
        $backup = $this->makeRecord();

        $this->restoreServiceSucceeds();

        Artisan::call("vanguard:restore {$backup->id} --force");

        $requestedBy = (string) RestoreRecord::latest('id')->first()->requested_by;

        // Not a username lookup: the console has no authenticated user. What
        // it can state honestly is the shell account and the machine, which is
        // what an audit of "who restored production" actually starts from.
        // An identity and nothing else — where the run came from is its own
        // column, not a prefix glued onto this one.
        $this->assertNotSame('', $requestedBy);
        $this->assertStringNotContainsString('console:', $requestedBy);
        $this->assertStringContainsString((string) (get_current_user() ?: getenv('USER')), $requestedBy);
    }

    #[Test]
    public function the_row_says_the_restore_came_from_the_console(): void
    {
        $backup = $this->makeRecord();

        $this->restoreServiceSucceeds();

        Artisan::call("vanguard:restore {$backup->id} --force");

        $this->assertSame('console', RestoreRecord::latest('id')->first()->origin);
    }

    #[Test]
    public function a_console_restore_fires_the_events_the_notifications_hang_off(): void
    {
        Event::fake([RestoreStarted::class, RestoreCompleted::class, RestoreFailed::class]);

        $backup = $this->makeRecord();
        $this->restoreServiceSucceeds();

        Artisan::call("vanguard:restore {$backup->id} --force");

        Event::assertDispatched(RestoreStarted::class);
        Event::assertDispatched(RestoreCompleted::class);
        Event::assertNotDispatched(RestoreFailed::class);
    }

    #[Test]
    public function a_failed_console_restore_fires_the_failure_event(): void
    {
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $this->restoreServiceThrows('gzip: unexpected end of file');

        Artisan::call("vanguard:restore {$backup->id} --force");

        Event::assertDispatched(RestoreFailed::class);
    }

    #[Test]
    public function a_rehearsal_is_recorded_as_a_rehearsal_and_not_as_a_restore_of_the_target(): void
    {
        $backup = $this->makeRecord(['tenant_id' => 'acme', 'type' => 'tenant']);

        $this->restoreServiceSucceeds();

        Artisan::call("vanguard:restore {$backup->id} --database=vanguard_rehearsal --force");

        $restore = RestoreRecord::latest('id')->first();

        // Without this the history reads "tenant acme was restored" for a run
        // that never touched acme's database, which is worse than no history.
        $this->assertSame('vanguard_rehearsal', $restore->target_database);
    }

    #[Test]
    public function a_real_restore_records_no_rehearsal_target(): void
    {
        $backup = $this->makeRecord();

        $this->restoreServiceSucceeds();

        Artisan::call("vanguard:restore {$backup->id} --force");

        $this->assertNull(RestoreRecord::latest('id')->first()->target_database);
    }

    #[Test]
    public function a_restore_the_operator_declines_leaves_no_row(): void
    {
        $backup = $this->makeRecord();

        $this->app->instance(RestoreService::class, Mockery::mock(RestoreService::class));

        $this->artisan("vanguard:restore {$backup->id}")
            ->expectsConfirmation('Do you want to proceed?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, RestoreRecord::count(), 'a cancelled restore was written to the history');
    }

    #[Test]
    public function a_restore_refused_before_it_starts_leaves_no_row(): void
    {
        $this->app->instance(RestoreService::class, Mockery::mock(RestoreService::class));

        // No backup with this id: the command fails before there is anything
        // to restore, so there is nothing to record either.
        $this->assertSame(1, Artisan::call('vanguard:restore 9999 --force'));

        $this->assertSame(0, RestoreRecord::count());
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function restoreServiceSucceeds(): void
    {
        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturn(true);

        $this->app->instance(RestoreService::class, $service);
    }

    private function restoreServiceThrows(string $message): void
    {
        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andThrow(new RuntimeException($message));

        $this->app->instance(RestoreService::class, $service);
    }
}
