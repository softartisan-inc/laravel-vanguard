<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        return new StreamedResponse(function () use ($request) {
            // Disable output buffering so events reach the client immediately
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Required SSE headers are set below in headers()
            // Send initial connection confirmation
            $this->sendEvent('connected', ['status' => 'ok', 'driver' => 'sse']);

            $interval    = config('vanguard.realtime.sse_interval', 2);   // seconds between DB polls
            $maxLifetime = config('vanguard.realtime.max_lifetime', 120); // close after N seconds
            $started     = time();
            $lastSnapshot = $this->snapshot();

            while (true) {
                // Respect max lifetime to avoid zombie connections
                if ((time() - $started) >= $maxLifetime) {
                    $this->sendEvent('close', ['reason' => 'max_lifetime']);
                    break;
                }

                // Check if client disconnected
                if (connection_aborted()) {
                    break;
                }

                sleep($interval);

                // Compare current DB state with last known state
                $current = $this->snapshot();

                if ($current !== $lastSnapshot) {
                    $this->sendEvent('vanguard', [
                        'type'    => 'backup.updated',
                        'stats'   => $this->quickStats(),
                        'updated' => now()->toIso8601String(),
                    ]);
                    $lastSnapshot = $current;
                } else {
                    // Send a heartbeat to keep the connection alive
                    $this->sendHeartbeat();
                }

                // Flush output to the client
                if (function_exists('fastcgi_finish_request')) {
                    // Not applicable in SSE — skip
                } else {
                    flush();
                }
            }
        }, 200, $this->sseHeaders());
    }

    // ─── Helpers ──────────────────────────────────────────────────

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

        return serialize([$counts, $latest]);
    }

    protected function quickStats(): array
    {
        return [
            'total_backups'   => BackupRecord::count(),
            'running_backups' => BackupRecord::running()->count(),
            'failed_recent'   => BackupRecord::failed()
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
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // Disable Nginx buffering
            'Connection'        => 'keep-alive',
        ];
    }
}
