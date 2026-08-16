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
        $restore = RestoreRecord::find($this->restoreId);

        if ($restore === null) {
            Log::warning('[Vanguard] Restore row disappeared before its job ran', [
                'restore_id' => $this->restoreId,
            ]);

            return;
        }

        $backup = $restore->backup;

        if ($backup === null) {
            $restore->markFailed('The backup this restore targets no longer exists.');
            event(new RestoreFailed($restore, new \RuntimeException('Backup missing')));

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
                'on_phase' => fn (string $phase, array $context = []) => $restore->markPhase($phase),
            ]);

            $restore->markCompleted();
            event(new RestoreCompleted($restore->fresh()));
        } catch (\Throwable $e) {
            $restore->markFailed($e->getMessage());
            event(new RestoreFailed($restore->fresh(), $e));

            Log::error('[Vanguard] Restore failed', [
                'restore_id' => $restore->id,
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
