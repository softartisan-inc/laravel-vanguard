<?php

namespace SoftArtisan\Vanguard\Services;

use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;

/**
 * Closes rows left `running` by a worker that never came back.
 *
 * A backup or restore marks itself running before it starts and completed or
 * failed when it ends. Nothing writes that ending when the process is killed
 * outright — an OOM kill, a deploy, a host restart — so the row keeps saying
 * "in progress" for ever. That is the worst shape a backup failure can take:
 * the dashboard renders it as work under way, the freshness probe still sees a
 * recent row, and no alert is sent. The operator is told everything is fine
 * about an archive that does not exist.
 *
 * The operations console already reported this state as a `stalled` warning
 * past the queue timeout. Reporting it in a screen nobody is watching at 3 a.m.
 * is not the same as closing it and alerting, which is what this does.
 *
 * The threshold is deliberately the queue timeout and nothing new: past it the
 * queue itself would have given up on the job, so the row can no longer move on
 * its own whatever happens. A timeout of 0 means "no timeout" — the same
 * convention the operations console uses — and disables the sweep entirely
 * rather than declaring every long job dead.
 */
class StaleRunReaper
{
    /**
     * Close every stale run and alert on each one.
     *
     * @return array{backups: int, restores: int} How many rows were closed.
     */
    public function reap(): array
    {
        $timeout = (int) config('vanguard.queue.timeout', 3600);

        if ($timeout <= 0) {
            return ['backups' => 0, 'restores' => 0];
        }

        return [
            'backups' => $this->reapBackups($timeout),
            'restores' => $this->reapRestores($timeout),
        ];
    }

    protected function reapBackups(int $timeout): int
    {
        $closed = 0;

        foreach (BackupRecord::query()->where('status', 'running')->get() as $record) {
            if (! $this->isStale($record, $timeout)) {
                continue;
            }

            $reason = $this->reason('backup', $timeout);

            $record->forceFill([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ])->save();

            // Through the existing failure event, so the reclaim reaches the
            // very notification channels a real failure would have used. A
            // reclaimed row that does not travel this path is still silent.
            event(new BackupFailed($record, new \RuntimeException($reason)));

            $closed++;
        }

        return $closed;
    }

    protected function reapRestores(int $timeout): int
    {
        $closed = 0;

        foreach (RestoreRecord::query()->where('status', 'running')->get() as $record) {
            if (! $this->isStale($record, $timeout)) {
                continue;
            }

            $reason = $this->reason('restore', $timeout);

            $record->forceFill([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ])->save();

            event(new RestoreFailed($record, new \RuntimeException($reason)));

            $closed++;
        }

        return $closed;
    }

    /**
     * Age the row against started_at, falling back to created_at.
     *
     * A process killed between the INSERT and the started_at stamp would
     * otherwise have no anchor at all and stay running for ever — the exact
     * state this class exists to end.
     */
    protected function isStale(BackupRecord|RestoreRecord $record, int $timeout): bool
    {
        $anchor = $record->started_at ?? $record->created_at;

        if ($anchor === null) {
            return false;
        }

        return $anchor->diffInSeconds(now(), absolute: true) > $timeout;
    }

    protected function reason(string $kind, int $timeout): string
    {
        return sprintf(
            'Reclaimed by Vanguard: this %s was still running %d s after it started, past the queue '
            .'timeout of %d s, with no completion signal. The worker was killed — out of memory, a '
            .'deploy, or a host restart — so whatever it had written is incomplete and must not be '
            .'trusted as a %s.',
            $kind,
            $timeout,
            $timeout,
            $kind === 'backup' ? 'backup' : 'restore',
        );
    }
}
