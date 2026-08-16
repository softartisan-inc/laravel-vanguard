<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Evidence, not configuration.
 *
 * Every section here answers a question the old dashboard answered from
 * config alone — and answered wrongly for five months in 2026. A destination
 * is writable because something was just written to it; a cron is alive
 * because it left a stamp; a target is fresh because a backup of it completed.
 */
class HealthController extends Controller
{
    public function __construct(protected TenancyResolver $tenancy) {}

    /**
     * GET /vanguard/api/health
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'destinations' => $this->destinations(),
            'alerts' => $this->alerts(),
            'schedule' => $this->schedule(),
            'queue' => $this->queue(),
            'retention' => [
                'enabled' => (bool) config('vanguard.retention.enabled', true),
                'days' => (int) config('vanguard.retention.days', 30),
            ],
            'freshness' => $this->freshness(),
        ]);
    }

    // ─── Destinations ─────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    protected function destinations(): array
    {
        $out = [];

        foreach (['local', 'remote', 'ftp'] as $name) {
            $config = config("vanguard.destinations.{$name}", []);
            $enabled = (bool) ($config['enabled'] ?? false);
            $disk = $config['disk'] ?? null;
            $path = $config['path'] ?? 'vanguard-backups';

            // A disabled destination is not probed, and reports writable=null:
            // unknown, not false. Nothing was tried, so nothing is claimed.
            [$writable, $reason] = $enabled && $disk
                ? $this->probe((string) $disk, (string) $path)
                : [null, null];

            $out[] = [
                'name' => $name,
                'enabled' => $enabled,
                'disk' => $disk,
                'path' => $path,
                'writable' => $writable,
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * Write a witness object, read it back, delete it.
     *
     * The round trip is the whole point: a bucket can accept a configuration,
     * a listing and a HEAD while refusing every PUT, which is exactly the
     * shape of the failure that went unnoticed from March to August 2026.
     *
     * The object is fourteen bytes and is removed in a finally block, so a
     * probe that throws part-way leaves nothing behind (spec §12).
     *
     * @return array{0: bool, 1: string|null} writable, and the reason if not
     */
    protected function probe(string $disk, string $path): array
    {
        $key = rtrim($path, '/').'/.vanguard-probe-'.bin2hex(random_bytes(8));
        $payload = 'vanguard-probe';

        try {
            if (Storage::disk($disk)->put($key, $payload) === false) {
                return [false, "Disk [{$disk}] refused the write."];
            }

            if (Storage::disk($disk)->get($key) !== $payload) {
                return [false, "Disk [{$disk}] accepted the write but read back something else."];
            }

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            try {
                Storage::disk($disk)->delete($key);
            } catch (\Throwable $e) {
                Log::warning('[Vanguard] The health probe could not remove its witness object', [
                    'disk' => $disk,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ─── Alerts ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function alerts(): array
    {
        // set / absent, never the value. This payload reaches a browser, and
        // a Slack webhook URL is a credential: anyone holding it can post as
        // the application (spec §7).
        return [
            'mail' => config('vanguard.notifications.mail.to') ? 'set' : 'absent',
            'slack' => config('vanguard.notifications.slack.webhook_url') ? 'set' : 'absent',
            'mail_enabled' => (bool) config('vanguard.notifications.mail.enabled', true),
            'slack_enabled' => (bool) config('vanguard.notifications.slack.enabled', false),
            'on_failure' => (bool) config('vanguard.notifications.on_failure', true),
            'on_success' => (bool) config('vanguard.notifications.on_success', false),
        ];
    }

    // ─── Schedule ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function schedule(): array
    {
        $lastSeen = VanguardScheduler::lastSeenAt();
        $interval = VanguardScheduler::globalIntervalSeconds();

        return [
            'enabled' => (bool) config('vanguard.schedule.enabled', true),
            'frequency' => config('vanguard.schedule.frequency', 'daily'),
            'cron' => VanguardScheduler::globalCron(),
            'timezone' => config('vanguard.schedule.timezone', config('app.timezone', 'UTC')),
            'interval_seconds' => $interval,
            'last_seen_at' => $lastSeen?->toIso8601String(),
            // No stamp at all is the March 2026 shape: a flawless
            // configuration and a cron that never ran once.
            'alive' => $lastSeen !== null && $lastSeen->greaterThan(now()->subSeconds(2 * $interval)),
        ];
    }

    // ─── Queue ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function queue(): array
    {
        $connection = config('vanguard.queue.connection');
        $name = (string) config('vanguard.queue.queue', 'vanguard');

        $pending = null;
        $reason = null;

        try {
            $pending = Queue::connection($connection)->size($name);
        } catch (\Throwable $e) {
            // Unknown, not zero. A Redis that is down and a queue that is
            // empty are indistinguishable to a caller handed a 0, and the
            // difference is whether the restore they just queued will ever
            // run (spec §12).
            $reason = $e->getMessage();
        }

        return [
            'enabled' => (bool) config('vanguard.queue.enabled', true),
            'connection' => $connection ?: config('queue.default'),
            'queue' => $name,
            'pending' => $pending,
            'reason' => $reason,
            'timeout' => (int) config('vanguard.queue.timeout', 3600),
        ];
    }

    // ─── Freshness ────────────────────────────────────────────────

    /**
     * Age of the last successful backup, per target.
     *
     * The only indicator on this screen that turns red on its own when
     * nothing runs at all — every other one describes an intention.
     *
     * Nothing here is allowed to take the rest of the payload down with it:
     * a central database that cannot be reached is exactly the outage this
     * page exists to surface, and it plausibly co-occurs with a broken
     * backup pipeline. The tenant enumeration and the per-target reads are
     * each guarded on their own, so a tenancy failure still leaves the
     * landlord row readable rather than emptying the whole section.
     *
     * @return array<string, mixed>
     */
    protected function freshness(): array
    {
        $threshold = 2 * VanguardScheduler::globalIntervalSeconds();
        $central = Vanguard::centralConnection();

        $targets = [['id' => null, 'label' => 'landlord']];
        $reason = null;

        try {
            if ($this->tenancy->isEnabled()) {
                foreach ($this->tenancy->allTenants() as $tenant) {
                    $key = (string) $tenant->getTenantKey();
                    $targets[] = ['id' => $key, 'label' => $key];
                }
            }
        } catch (\Throwable $e) {
            // A tenancy failure should not cost us the landlord row: fall
            // back to it alone and report why the tenant list could not be
            // read.
            $targets = [['id' => null, 'label' => 'landlord']];
            $reason = $e->getMessage();
        }

        $rows = [];

        foreach ($targets as $target) {
            try {
                $query = BackupRecord::on($central)->completed();

                if ($target['id'] === null) {
                    $query->whereNull('tenant_id');
                } else {
                    $query->forTenant($target['id']);
                }

                $latest = $query->orderByDesc('completed_at')->first();

                $age = $latest?->completed_at
                    ? (int) now()->diffInSeconds($latest->completed_at, true)
                    : null;

                $rows[] = [
                    'target' => $target['label'],
                    'tenant_id' => $target['id'],
                    'last_success_at' => $latest?->completed_at?->toIso8601String(),
                    'age_seconds' => $age,
                    // Twice the interval, not once, so a run that slipped or a
                    // backup that took an hour raises no false alarm. Never
                    // backed up counts as stale: it is the worse case, not the
                    // unknown one.
                    'stale' => $age === null || $age > $threshold,
                ];
            } catch (\Throwable $e) {
                // Unreadable, not absent: a row we could not read is dropped
                // from the list rather than faked, and the reason travels up
                // so the page still says why instead of just going blank.
                $reason = $e->getMessage();
            }
        }

        return [
            'threshold_seconds' => $threshold,
            'targets' => $rows,
            'reason' => $reason,
        ];
    }
}
