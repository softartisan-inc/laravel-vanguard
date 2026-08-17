<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use SoftArtisan\Vanguard\Http\Concerns\ProbesQueueDepth;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Vanguard;

/**
 * What is happening right now, and whether anything is stuck.
 *
 * The rest of the dashboard answers "what happened": lists, histories,
 * freshness. During an incident the question is the other one, and answering
 * it used to mean reading three screens and inferring the fourth — the backup
 * list filtered on running, the restore history filtered on running, the
 * health page for the queue depth, and nothing at all for "there is no worker".
 *
 * One payload rather than four calls, because the interesting answer is a
 * relation between them: a restore that has been pending for six minutes is
 * only alarming next to a queue nothing is consuming. That judgement is made
 * here, once, so a browser cannot make it differently from a monitoring probe.
 */
class OperationsApiController extends Controller
{
    use ProbesQueueDepth;

    /**
     * How long a row may sit in `pending` before it is worth saying so.
     *
     * A job takes a moment to be picked up, and a screen that shouts at every
     * dispatch is a screen operators learn to ignore. Two minutes is longer
     * than any healthy pickup and far shorter than an incident.
     */
    public const PENDING_GRACE_SECONDS = 120;

    /**
     * How many rows of each kind travel in one payload.
     *
     * This screen is polled; it is not a history. What is running now is a
     * handful of rows on any installation, and a thousand of them is itself
     * the incident.
     */
    public const MAX_ROWS = 50;

    /**
     * GET /vanguard/api/operations
     */
    public function show(): JsonResponse
    {
        $central = Vanguard::centralConnection();
        $queue = $this->queueSnapshot();

        $runningBackups = BackupRecord::on($central)->running()
            ->latest()->limit(self::MAX_ROWS)->get();
        $runningRestores = RestoreRecord::on($central)->running()
            ->latest()->limit(self::MAX_ROWS)->get();

        $waitingBackups = BackupRecord::on($central)->where('status', 'pending')
            ->latest()->limit(self::MAX_ROWS)->get();
        $waitingRestores = RestoreRecord::on($central)->where('status', 'pending')
            ->latest()->limit(self::MAX_ROWS)->get();

        // A day, like the failure counters everywhere else on the dashboard:
        // during an incident what failed this morning is context, and what
        // failed last week is history.
        $failedBackups = BackupRecord::on($central)->failed()
            ->where('created_at', '>=', now()->subDay())
            ->latest()->limit(self::MAX_ROWS)->get();
        $failedRestores = RestoreRecord::on($central)->failed()
            ->where('created_at', '>=', now()->subDay())
            ->latest()->limit(self::MAX_ROWS)->get();

        return response()->json([
            // The server's clock, so the browser can keep the elapsed times
            // ticking between polls without trusting its own.
            'generated_at' => now()->toIso8601String(),
            'running' => [
                'backups' => $runningBackups->map(fn ($r) => $this->formatBackup($r))->all(),
                'restores' => $runningRestores->map(fn ($r) => $this->formatRestore($r))->all(),
            ],
            'waiting' => [
                'backups' => $waitingBackups->map(fn ($r) => $this->formatBackup($r))->all(),
                'restores' => $waitingRestores->map(fn ($r) => $this->formatRestore($r))->all(),
            ],
            'recent_failures' => [
                'backups' => $failedBackups->map(fn ($r) => $this->formatBackup($r))->all(),
                'restores' => $failedRestores->map(fn ($r) => $this->formatRestore($r))->all(),
            ],
            'queue' => $queue,
            'warnings' => $this->warnings(
                $queue,
                $runningBackups->concat($runningRestores),
                $waitingBackups->concat($waitingRestores),
            ),
        ]);
    }

    /**
     * The judgements this screen exists to make.
     *
     * Each one names the row it is about and what to do about it. "Something
     * is wrong" is what the operator already knows by the time they open this.
     *
     * @param  array<string, mixed>  $queue
     * @param  Collection<int, Model>  $running
     * @param  Collection<int, Model>  $waiting
     * @return list<array<string, mixed>>
     */
    protected function warnings(array $queue, $running, $waiting): array
    {
        $out = [];

        // Unknown, not zero. A Redis that is down and an empty queue look the
        // same to anyone handed a 0, and the difference is whether the restore
        // that was just queued will ever run.
        if ($queue['pending'] === null) {
            $out[] = [
                'level' => 'danger',
                'code' => 'queue_unreadable',
                'message' => sprintf(
                    'The queue [%s] on connection [%s] cannot be read: %s. Nothing here can be trusted to start.',
                    $queue['queue'],
                    $queue['connection'],
                    $queue['reason'] ?? 'unknown reason',
                ),
            ];
        }

        $stale = $waiting->filter(
            fn ($row) => $this->elapsed($row->created_at) >= self::PENDING_GRACE_SECONDS,
        );

        // The failure this screen was asked for by name: a restore sitting in
        // pending with nothing consuming the queue. The rows are the evidence;
        // the command is what ends it.
        if ($stale->isNotEmpty()) {
            $out[] = [
                'level' => 'danger',
                'code' => 'no_worker',
                'message' => sprintf(
                    '%d job%s %s been waiting more than %d minutes on queue [%s]. '
                    .'Nothing appears to be consuming it — start a worker: '
                    .'php artisan queue:work %s--queue=%s',
                    $stale->count(),
                    $stale->count() === 1 ? '' : 's',
                    $stale->count() === 1 ? 'has' : 'have',
                    (int) (self::PENDING_GRACE_SECONDS / 60),
                    $queue['queue'],
                    $queue['connection'] ? $queue['connection'].' ' : '',
                    $queue['queue'],
                ),
                'rows' => $stale->map(fn ($row) => [
                    'kind' => $row instanceof RestoreRecord ? 'restore' : 'backup',
                    'id' => $row->id,
                    'waiting_seconds' => $this->elapsed($row->created_at),
                ])->values()->all(),
            ];
        }

        // Longer than the queue's own timeout means the worker holding it was
        // killed, or the job is past the point where the queue would have
        // retried it. Either way the row will never move on its own.
        $timeout = (int) config('vanguard.queue.timeout', 3600);

        $stalled = $running->filter(
            fn ($row) => $timeout > 0 && $this->elapsed($row->started_at ?? $row->created_at) > $timeout,
        );

        if ($stalled->isNotEmpty()) {
            $out[] = [
                'level' => 'warn',
                'code' => 'stalled',
                'message' => sprintf(
                    '%d job%s been running longer than the queue timeout (%d s). '
                    .'A worker that was killed leaves its row running for ever.',
                    $stalled->count(),
                    $stalled->count() === 1 ? ' has' : 's have',
                    $timeout,
                ),
                'rows' => $stalled->map(fn ($row) => [
                    'kind' => $row instanceof RestoreRecord ? 'restore' : 'backup',
                    'id' => $row->id,
                    'elapsed_seconds' => $this->elapsed($row->started_at ?? $row->created_at),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatBackup(BackupRecord $r): array
    {
        return [
            'kind' => 'backup',
            'id' => $r->id,
            'type' => $r->type,
            'tenant_id' => $r->tenant_id,
            'target' => $r->tenant_id ?? $r->type,
            'status' => $r->status,
            // Backups have no phase; the key is there so one component can
            // render both kinds without asking which it holds.
            'phase' => null,
            'error' => $r->error,
            'created_at' => $r->created_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'elapsed_seconds' => $this->elapsed($r->started_at ?? $r->created_at, $r->completed_at),
            'waiting_seconds' => $this->elapsed($r->created_at, $r->started_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatRestore(RestoreRecord $r): array
    {
        return [
            'kind' => 'restore',
            'id' => $r->id,
            'backup_id' => $r->backup_id,
            'type' => $r->type,
            'tenant_id' => $r->tenant_id,
            'target' => $r->tenant_id ?? $r->type,
            'status' => $r->status,
            // The one thing that moves while a restore holds the same status
            // for minutes: without it the screen looks like it has hung.
            'phase' => $r->phase,
            'error' => $r->error,
            'requested_by' => $r->requested_by,
            'created_at' => $r->created_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'elapsed_seconds' => $this->elapsed($r->started_at ?? $r->created_at, $r->completed_at),
            'waiting_seconds' => $this->elapsed($r->created_at, $r->started_at),
        ];
    }

    /**
     * Seconds between two moments, counted to now when the second is missing.
     *
     * Computed here rather than in the browser: a workstation whose clock is
     * four minutes off would otherwise report four minutes of work on a job
     * that just started, or none at all on one that is stuck.
     */
    protected function elapsed(?\DateTimeInterface $from, ?\DateTimeInterface $to = null): ?int
    {
        if ($from === null) {
            return null;
        }

        return max(0, ($to ? $to->getTimestamp() : now()->getTimestamp()) - $from->getTimestamp());
    }
}
