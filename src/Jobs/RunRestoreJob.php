<?php

namespace SoftArtisan\Vanguard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\RestoreService;

/**
 * Runs a restore off the queue, keeping its history row current.
 *
 * A restore of a live tenant runs for minutes: doing it inside the HTTP request
 * loses the answer to a proxy timeout while the server carries on regardless.
 * The row is the single place that knows what happened, including the exact
 * error the HTTP layer refuses to disclose.
 */
class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    /**
     * One attempt: a restore writes to a live database, so retrying it
     * automatically would replay a partial write nobody asked for.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $restoreId,
    ) {
        $this->timeout = (int) config('vanguard.queue.timeout', 3600);
    }

    public function handle(RestoreService $restoreService): void
    {
        // Captured before anything can touch tenancy: stancl/tenancy swaps the
        // default database connection for the duration of
        // tenancy()->initialize()/end() (see TenancyResolver::tenantDbConfig()
        // and the comment above it). Every write this job makes to
        // vanguard_restores must land on the central connection, never on
        // whatever tenant connection happens to be active while it runs.
        // Never hardcode 'central' — on this product's production installs
        // central_connection resolves to 'mysql', and hardcoding the literal
        // string has already shipped as a bug three times in a sibling package.
        $central = config('tenancy.database.central_connection', config('database.default'));

        $restore = RestoreRecord::on($central)->find($this->restoreId);

        if ($restore === null) {
            Log::warning('[Vanguard] Restore row disappeared before its job ran', [
                'restore_id' => $this->restoreId,
            ]);

            return;
        }

        // Pin the instance itself too: markRunning()/markCompleted() below
        // reuse $restore->update(). They currently run outside the tenancy
        // window, but a worker process that crashed mid-tenancy on a prior
        // job could in principle leave the default connection swapped even
        // there — pinning removes the need to trust that.
        $restore->setConnection($central);

        // Read explicitly on the same pinned connection rather than through
        // the backup() relation: Eloquent relations do not inherit their
        // parent's connection, so a lazy $restore->backup would resolve
        // BackupRecord's own connection dynamically — vulnerable to exactly
        // the same swapped-default risk this whole method exists to avoid.
        $backup = $restore->backup_id !== null
            ? BackupRecord::on($central)->find($restore->backup_id)
            : null;

        if ($backup === null) {
            $message = 'The backup this restore targets no longer exists.';

            // started_at is written alongside the failure so a row that
            // never ran still records when the attempt was made, matching
            // every other row.
            $this->persistFailure($restore, $message, ['started_at' => now()]);
            event(new RestoreFailed($restore, new \RuntimeException($message)));

            return;
        }

        $restore->markRunning();
        event(new RestoreStarted($restore));

        try {
            $restoreService->restore($backup, [
                'source' => $restore->source,
                'restore_db' => $restore->restore_db,
                'restore_storage' => $restore->restore_storage,
                'verify_checksum' => $restore->verify_checksum,
                // Never from a job: replace-mode stays a console decision.
                'wipe_storage' => false,
                'on_phase' => function (string $phase, array $context = []) use ($central) {
                    // Written by primary key on the pinned connection rather
                    // than through the model instance: this closure runs
                    // inside RestoreService::restoreTenant()'s tenancy window,
                    // exactly where the default connection is swapped.
                    RestoreRecord::on($central)->whereKey($this->restoreId)->update([
                        'phase' => $phase,
                        'updated_at' => now(),
                    ]);
                },
            ]);

            $restore->markCompleted();
            event(new RestoreCompleted($restore));
        } catch (\Throwable $e) {
            $this->persistFailure($restore, $e->getMessage());
            event(new RestoreFailed($restore, $e));

            Log::error('[Vanguard] Restore failed', [
                'restore_id' => $restore->id,
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called by the queue worker when the job never reaches its own catch
     * block: the configured timeout firing, a worker SIGKILL, a fatal error,
     * an OOM. Without this the row is left at status=running forever with a
     * stale phase, RestoreFailed never fires, and no alert is sent — exactly
     * the silent failure this history row exists to abolish.
     */
    public function failed(\Throwable $e): void
    {
        $central = config('tenancy.database.central_connection', config('database.default'));

        $restore = RestoreRecord::on($central)->find($this->restoreId);

        if ($restore === null || ! in_array($restore->status, ['pending', 'running'], true)) {
            // Either the row is gone, or handle()'s own catch block already
            // resolved it before the worker gave up on the job.
            return;
        }

        $restore->setConnection($central);
        $this->persistFailure($restore, $e->getMessage());

        event(new RestoreFailed($restore, $e));
    }

    /**
     * Persist a failure to the history row without letting a persistence
     * failure suppress the alert that follows it.
     *
     * DatabaseDriver builds messages as label + exit code + captured stderr,
     * and under strict SQL mode an oversized UPDATE throws from inside what
     * used to be the catch block itself — silencing the event() call right
     * after it. The row still gets the truncated message RestoreRecord::
     * markFailed() now writes; only a write that fails outright is caught
     * here.
     */
    protected function persistFailure(RestoreRecord $restore, string $error, array $extra = []): void
    {
        try {
            $restore->markFailed($error, $extra);
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Could not persist the restore failure to its history row', [
                'restore_id' => $restore->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
