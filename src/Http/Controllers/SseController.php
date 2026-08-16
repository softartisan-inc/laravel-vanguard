<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Vanguard;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    /**
     * How many recent rows of each kind the fingerprint covers.
     *
     * Bounded so the poll stays cheap on an installation with years of
     * history, and large enough that anything moving is inside it: a change
     * to a row two hundred backups old is not news.
     */
    public const RECENT_ROWS = 200;

    /**
     * GET /vanguard/api/stream
     *
     * Opens a persistent SSE connection. The client receives a "vanguard" event
     * whenever a backup record changes status (running → completed/failed).
     *
     * The endpoint polls the DB at a configurable interval and pushes a diff
     * only when something changed — zero noise on idle systems.
     *
     * Connection lifecycle:
     *   - Client connects once on page load
     *   - Server streams events as they happen
     *   - Client auto-reconnects on disconnect (native EventSource behaviour)
     *   - Connection closes after max_lifetime seconds to free server resources
     */
    public function stream(Request $request): StreamedResponse
    {
        return new StreamedResponse(function () {
            // Remove PHP's execution time limit for the duration of this stream.
            // On Linux, sleep() does not count toward max_execution_time, but this
            // makes the behaviour explicit and portable across server configurations.
            set_time_limit(0);

            // Disable output buffering so events reach the client immediately.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $this->sendEvent('connected', ['status' => 'ok', 'driver' => 'sse']);

            $interval = config('vanguard.realtime.sse_interval', 2);
            $maxLifetime = config('vanguard.realtime.max_lifetime', 120);
            $started = time();
            $lastSnapshot = $this->snapshot();

            // The connection released here — and reconnected/disconnected in
            // pollSnapshot() and the finally block below — must be the one
            // snapshot() and quickStats() actually read: Vanguard's central
            // connection, pinned because stancl/tenancy swaps the default
            // connection underneath. Disconnecting the default here would
            // leave the central connection open for the whole max_lifetime.
            $connection = Vanguard::centralConnection();

            // Release the DB connection immediately after the first snapshot so
            // the connection slot is not held open during the sleep periods.
            // A fresh connection is acquired only when the next poll runs.
            DB::connection($connection)->disconnect();

            try {
                while (true) {
                    if ((time() - $started) >= $maxLifetime) {
                        $this->sendEvent('close', ['reason' => 'max_lifetime']);
                        break;
                    }

                    if (connection_aborted()) {
                        break;
                    }

                    sleep($interval);

                    $current = $this->pollSnapshot();

                    // Null is "this cycle told us nothing", not "nothing
                    // changed": the next poll will see whatever it missed,
                    // because the comparison is against the last snapshot that
                    // actually came back.
                    if ($current === null) {
                        $this->sendHeartbeat();

                        continue;
                    }

                    if ($current !== $lastSnapshot) {
                        // 'backup.updated' also covers restores now. The name
                        // is kept until phase 3 rebuilds the JS bundle that
                        // reads it; renaming it here would ship a dashboard
                        // that silently stops updating.
                        $this->sendEvent('vanguard', [
                            'type' => 'backup.updated',
                            'stats' => $this->quickStats(),
                            'updated' => now()->toIso8601String(),
                        ]);
                        $lastSnapshot = $current;
                    } else {
                        $this->sendHeartbeat();
                    }
                }
            } finally {
                // Restore the connection for any cleanup Laravel may perform after
                // the response — guaranteed even if an exception interrupts the loop.
                DB::connection($connection)->reconnect();
            }
        }, 200, $this->sseHeaders());
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Reconnect, take one snapshot, disconnect — and survive a failure.
     *
     * The reconnection sits inside the loop so the connection slot is free
     * during the sleeps, which also means a database restarted between two
     * polls raises here. Uncaught, that exception left the streamed response
     * and every open dashboard lost its stream at once, over a blip that cost
     * one cycle. A failed poll is worth a log line and a heartbeat, not the
     * connection.
     *
     * @return string|null The snapshot, or null when this cycle could not read it
     */
    protected function pollSnapshot(): ?string
    {
        // Must match the connection snapshot() and quickStats() read
        // through — Vanguard's pinned central connection, not the default
        // one stancl/tenancy may have swapped — or this reconnect/disconnect
        // pair manages a connection nobody queries, and the one that is
        // queried is never released between polls.
        $connection = Vanguard::centralConnection();

        try {
            DB::connection($connection)->reconnect();

            try {
                return $this->snapshot();
            } finally {
                DB::connection($connection)->disconnect();
            }
        } catch (\Throwable $e) {
            // Logged rather than swallowed: a database answering one poll in
            // three looks exactly like a system where nothing is happening.
            Log::warning('[Vanguard] The dashboard stream could not read its poll', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A fingerprint of every recent row, backups and restores alike.
     *
     * The previous snapshot was a status→count map plus the latest id — a
     * lossy aggregate, and proved blind on 16 August 2026: any set of
     * transitions leaving both the multiset of statuses and the maximum id
     * unchanged produced no event, which is the shape of --all-tenants with
     * a single worker. It also never queried vanguard_restores at all, so
     * every restore, and every phase of every restore, was invisible to the
     * channel the restore screen is built on.
     *
     * Hashing id:status:updated_at over a bounded window is exact for any
     * state change, catches creations and deletions, and reads only indexed
     * columns.
     */
    protected function snapshot(): string
    {
        // Pinned: vanguard_backups and vanguard_restores live on the central
        // connection, and stancl/tenancy swaps the default one underneath.
        $central = Vanguard::centralConnection();

        $backups = BackupRecord::on($central)
            ->orderByDesc('id')
            ->limit(static::RECENT_ROWS)
            ->get(['id', 'status', 'updated_at'])
            ->map(fn ($r) => 'b'.$r->id.':'.$r->status.':'.$r->updated_at?->getTimestamp())
            ->all();

        // The phase is part of the fingerprint: a restore holds one status
        // for minutes while moving through five phases, and a screen that
        // does not move looks like a screen that has hung.
        $restores = RestoreRecord::on($central)
            ->orderByDesc('id')
            ->limit(static::RECENT_ROWS)
            ->get(['id', 'status', 'phase', 'updated_at'])
            ->map(fn ($r) => 'r'.$r->id.':'.$r->status.':'.$r->phase.':'.$r->updated_at?->getTimestamp())
            ->all();

        return md5(implode('|', array_merge($backups, $restores)));
    }

    /**
     * @return array<string, int>
     */
    protected function quickStats(): array
    {
        $central = Vanguard::centralConnection();

        return [
            'total_backups' => BackupRecord::on($central)->count(),
            'running_backups' => BackupRecord::on($central)->running()->count(),
            'failed_recent' => BackupRecord::on($central)->failed()
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'running_restores' => RestoreRecord::on($central)->running()->count(),
            'failed_restores_recent' => RestoreRecord::on($central)->failed()
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    protected function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";
        flush();
    }

    protected function sendHeartbeat(): void
    {
        // SSE comment — ignored by client but keeps TCP connection alive
        echo ": heartbeat\n\n";
        flush();
    }

    protected function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // Disable Nginx buffering
            'Connection' => 'keep-alive',
        ];
    }
}
