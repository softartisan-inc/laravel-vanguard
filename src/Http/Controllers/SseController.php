<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Models\BackupRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
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

            // Release the DB connection immediately after the first snapshot so
            // the connection slot is not held open during the sleep periods.
            // A fresh connection is acquired only when the next poll runs.
            DB::connection()->disconnect();

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
                DB::connection()->reconnect();
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
        try {
            DB::connection()->reconnect();

            try {
                return $this->snapshot();
            } finally {
                DB::connection()->disconnect();
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
     * Lightweight DB snapshot — just counts per status.
     * Cheap query, no full record fetch.
     */
    protected function snapshot(): string
    {
        $counts = BackupRecord::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Also include the ID of the most recent record to catch new backups
        $latest = BackupRecord::latest()->value('id');

        return json_encode([$counts, $latest]);
    }

    protected function quickStats(): array
    {
        return [
            'total_backups' => BackupRecord::count(),
            'running_backups' => BackupRecord::running()->count(),
            'failed_recent' => BackupRecord::failed()
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
