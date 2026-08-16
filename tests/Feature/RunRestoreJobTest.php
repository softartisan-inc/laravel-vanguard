<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Tests\TestCase;

class RunRestoreJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_takes_a_restore_from_pending_to_completed(): void
    {
        Event::fake([RestoreStarted::class, RestoreCompleted::class, RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()->andReturnTrue();
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame('completed', $restore->fresh()->status);
        $this->assertNotNull($restore->fresh()->completed_at);

        Event::assertDispatched(RestoreStarted::class);
        Event::assertDispatched(RestoreCompleted::class);
        Event::assertNotDispatched(RestoreFailed::class);
    }

    #[Test]
    public function it_writes_the_exact_error_of_a_failed_restore(): void
    {
        // The HTTP layer answers "check server logs"; the row must not.
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andThrow(new RuntimeException('Checksum mismatch for backup #7.'));
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $restore = $restore->fresh();

        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('Checksum mismatch', $restore->error);

        Event::assertDispatched(RestoreFailed::class);
    }

    #[Test]
    public function it_records_the_phases_the_service_announces(): void
    {
        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $seenInDatabase = [];

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andReturnUsing(function ($record, array $options) use ($restore, &$seenInDatabase) {
                foreach (['downloading', 'verifying', 'restoring database'] as $phase) {
                    $options['on_phase']($phase);
                    // Read it back: the point is that the phase is persisted while
                    // the restore runs, which is what the dashboard will poll.
                    $seenInDatabase[] = $restore->fresh()->phase;
                }

                return true;
            });
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame(['downloading', 'verifying', 'restoring database'], $seenInDatabase);
        $this->assertSame('completed', $restore->fresh()->status);
        $this->assertNull($restore->fresh()->phase, 'a finished restore is in no phase');
    }

    #[Test]
    public function it_forwards_the_options_recorded_on_the_row(): void
    {
        $backup = $this->makeRecord();
        $restore = $this->makeRestore([
            'backup_id' => $backup->id,
            'status' => 'pending',
            'source' => 'remote',
            'restore_db' => true,
            'restore_storage' => true,
            'verify_checksum' => false,
        ]);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->withArgs(function ($record, array $options) {
                return $options['source'] === 'remote'
                    && $options['restore_storage'] === true
                    && $options['verify_checksum'] === false
                    && ($options['wipe_storage'] ?? false) === false;
            })
            ->andReturnTrue();
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $this->assertSame('completed', $restore->fresh()->status);
    }

    #[Test]
    public function it_does_nothing_when_the_row_is_gone(): void
    {
        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');

        (new RunRestoreJob(999999))->handle($service);

        $this->assertTrue(true, 'a deleted restore row must not crash the worker');
    }

    #[Test]
    public function it_fails_the_row_when_the_targeted_backup_is_gone(): void
    {
        Event::fake([RestoreStarted::class, RestoreCompleted::class, RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        // Delete the backup the restore targets. `backup_id` is nullOnDelete, and
        // SQLite foreign keys are enforced for this suite, so the FK clears itself
        // rather than blocking the delete — confirm that before trusting it.
        $backup->delete();
        $this->assertNull($restore->fresh()->backup_id, 'nullOnDelete should have cleared backup_id');

        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');

        (new RunRestoreJob($restore->id))->handle($service);

        $restore = $restore->fresh();

        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('backup', $restore->error);
        $this->assertStringContainsString('no longer exists', $restore->error);

        Event::assertDispatched(RestoreFailed::class);
        Event::assertNotDispatched(RestoreStarted::class);
        Event::assertNotDispatched(RestoreCompleted::class);
    }

    #[Test]
    public function the_missing_backup_alert_matches_the_row_and_the_row_records_a_start_time(): void
    {
        // Finding 6: the row and the alert must tell the same story, and a
        // row that never ran must still record when the attempt was made.
        Event::fake([RestoreFailed::class]);

        $restore = $this->makeRestore(['backup_id' => null, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldNotReceive('restore');
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        $restore = $restore->fresh();

        $this->assertNotNull($restore->started_at, 'a row that never ran must still record when it was attempted');
        $this->assertSame('The backup this restore targets no longer exists.', $restore->error);

        Event::assertDispatched(
            RestoreFailed::class,
            fn ($event) => $event->exception->getMessage() === $restore->error,
        );
    }

    #[Test]
    public function the_whole_job_reads_and_writes_the_central_connection_even_when_the_default_connection_is_already_wrong_at_start(): void
    {
        // Finding 1 (CRITICAL). A queue worker is a long-lived process that
        // handles many jobs in sequence. If an earlier restore's job never
        // reached tenancy()->end() — a timeout, a SIGKILL, a fatal error —
        // 'database.default' can already be pointing at a tenant connection
        // by the time *this* job's handle() starts, which is exactly the
        // reported production symptom: every write aborts with "Table
        // 'tenant_x.vanguard_restores' doesn't exist", before any restore
        // work runs. 'vanguard_test_tenant' stands in for that leftover
        // tenant connection: a second sqlite connection with none of
        // Vanguard's tables. 'tenancy.database.central_connection' is set
        // once here, the way it is in a real config file — fixed, not
        // derived from the mutable runtime default — which is exactly why
        // reading it (rather than 'database.default') is the fix.
        config(['database.connections.vanguard_test_tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        config(['tenancy.database.central_connection' => 'sqlite']);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andReturnUsing(function ($record, array $options) {
                $options['on_phase']('restoring database');

                return true;
            });
        $this->app->instance(RestoreService::class, $service);

        // The corruption is already in place before the job runs, and reset
        // in a finally so a failing assertion still leaves later tests (and
        // this test's own tearDown, which touches the default connection)
        // on a working connection.
        config(['database.default' => 'vanguard_test_tenant']);

        try {
            (new RunRestoreJob($restore->id))->handle($service);

            $this->assertSame(
                'completed',
                RestoreRecord::on('sqlite')->find($restore->id)->status,
                'the whole job — finding the row, reading its backup, announcing the phase, completing it — must use the central connection, never the corrupted default',
            );
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    #[Test]
    public function a_failing_history_write_never_prevents_the_failure_event(): void
    {
        // Finding 3 (part 2) + Finding 5: a write that fails inside the catch
        // block must not suppress the RestoreFailed event dispatched right
        // after it, and dispatching from the in-memory instance (not a
        // ->fresh() reload) is what makes that possible once the row can no
        // longer be read back.
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'pending']);

        $service = Mockery::mock(RestoreService::class);
        $service->shouldReceive('restore')->once()
            ->andReturnUsing(function () {
                // SQLite runs DDL inside a transaction, and RefreshDatabase
                // wraps every test in one — dropping the table here is
                // rolled back automatically when the test ends, so later
                // tests are unaffected. It forces the UPDATE inside
                // markFailed() to genuinely throw, rather than mocking that.
                Schema::connection('sqlite')->drop('vanguard_restores');

                throw new RuntimeException('boom');
            });
        $this->app->instance(RestoreService::class, $service);

        (new RunRestoreJob($restore->id))->handle($service);

        Event::assertDispatched(
            RestoreFailed::class,
            fn ($event) => $event->record->id === $restore->id
                && $event->exception->getMessage() === 'boom',
        );
    }

    #[Test]
    public function failed_marks_a_still_running_row_failed_and_raises_the_alert(): void
    {
        // Finding 2: a job that never reaches its own catch block (timeout,
        // SIGKILL, OOM) must still resolve the row via Laravel's failed()
        // callback, or it sits at status=running forever with no alert.
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore(['backup_id' => $backup->id, 'status' => 'running']);

        (new RunRestoreJob($restore->id))->failed(new RuntimeException('Job timed out'));

        $restore = $restore->fresh();

        $this->assertSame('failed', $restore->status);
        $this->assertStringContainsString('Job timed out', $restore->error);
        $this->assertNotNull($restore->completed_at);

        Event::assertDispatched(RestoreFailed::class, fn ($event) => $event->record->id === $restore->id);
    }

    #[Test]
    public function failed_leaves_an_already_resolved_row_alone(): void
    {
        // A row already marked completed or failed by handle()'s own catch
        // block must not be clobbered by a redundant failed() call.
        Event::fake([RestoreFailed::class]);

        $backup = $this->makeRecord();
        $restore = $this->makeRestore([
            'backup_id' => $backup->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        (new RunRestoreJob($restore->id))->failed(new RuntimeException('should be ignored'));

        $this->assertSame('completed', $restore->fresh()->status);
        Event::assertNotDispatched(RestoreFailed::class);
    }
}
