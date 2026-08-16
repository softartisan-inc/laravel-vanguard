<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
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
            //
            // Enabled with no disk is a different answer, and it used to give
            // this one: a failure, named. Nothing can be probed, but something
            // is definitely wrong — the operator switched the destination on
            // and the write path has nowhere to put the archive, so it either
            // dies mid-backup or, for a blank name, quietly falls through to
            // the application's default disk (BackupStorageManager refuses
            // both now).
            [$writable, $reason] = match (true) {
                ! $enabled => [null, null],
                $this->diskName($disk) === '' => [false, sprintf(
                    'Destination [%s] is enabled but names no disk: set vanguard.destinations.%s.disk '
                    .'to a disk declared in config/filesystems.php.',
                    $name,
                    $name,
                )],
                default => $this->probe($this->diskName($disk), (string) $path),
            };

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
     * The disk name a destination actually resolves to, or '' when it has none.
     *
     * Null and blank are the same configuration seen from two angles — an
     * unset key, and VANGUARD_REMOTE_DISK= in a .env file, which produces an
     * empty string the config default never gets to replace — so both answer
     * "no disk" rather than one crashing and one silently using the default.
     *
     * @param  mixed  $disk  The raw value of vanguard.destinations.<name>.disk
     */
    protected function diskName(mixed $disk): string
    {
        return is_string($disk) ? trim($disk) : '';
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
            //
            // The boundary is inclusive — a heartbeat landing exactly on twice
            // the interval is still alive — because that is the answer the
            // freshness rows have always given to the same question, and one
            // page cannot resolve one rule two ways: a red cron beside a green
            // freshness row on the same timestamp tells the operator nothing
            // except that one of them is wrong. Inclusive is also the reading
            // the tolerance was chosen for: twice the interval is what a run
            // may slip by, and a run that slipped by exactly that has not yet
            // missed anything. The alarm belongs one second later.
            'alive' => $lastSeen !== null && $lastSeen->greaterThanOrEqualTo(now()->subSeconds(2 * $interval)),
        ];
    }

    // ─── Queue ────────────────────────────────────────────────────

    /**
     * How long the probe is allowed to spend reaching the queue driver.
     *
     * Seconds, not a minute. Reading a list length is a single round trip; a
     * driver that cannot answer it in a second and a half is down, and saying
     * so is the answer this page exists to give.
     */
    protected const PROBE_TIMEOUT_SECONDS = 1.5;

    /**
     * @return array<string, mixed>
     */
    protected function queue(): array
    {
        $connection = config('vanguard.queue.connection');
        $name = (string) config('vanguard.queue.queue', 'vanguard');

        $pending = null;
        $reason = null;
        $probeRedis = null;

        try {
            [$probe, $probeRedis] = $this->queueProbe($connection);
            $pending = $probe->size($name);
        } catch (\Throwable $e) {
            // Unknown, not zero. A Redis that is down and a queue that is
            // empty are indistinguishable to a caller handed a 0, and the
            // difference is whether the restore they just queued will ever
            // run (spec §12).
            $reason = $e->getMessage();
        } finally {
            $this->closeProbeConnections($probeRedis);
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

    /**
     * Build a queue instance whose depth read cannot outlast the request.
     *
     * The try/catch in queue() bounds nothing on its own. A Redis that is
     * routable but silent — a moved container, a DROP rule, a host that
     * stopped answering — does not raise: the client sits in its connect
     * timeout, which is thirty seconds in a stock configuration and sixty in
     * several of ours. An auditor who pointed vanguard.queue.connection at one
     * waited more than a minute for the page that reports breakage, which is a
     * worse failure than a 500: the operator gets nothing, and a monitoring
     * probe times out with no payload to alarm on.
     *
     * The bound has to be in place before the client opens the socket —
     * phpredis connects eagerly inside the connector, and predis applies its
     * connect timeout at stream_socket_client() time — so lowering a timeout on
     * an already-resolved connection is too late. It also cannot be done
     * through config(): RedisManager is constructed with a snapshot of
     * database.redis (see RedisServiceProvider), so a connection written into
     * the config repository at request time does not exist as far as the
     * manager is concerned, and the queue fails with "Redis connection [...]
     * not configured" — on a perfectly healthy Redis.
     *
     * So the probe builds its own throwaway RedisManager over a bounded copy of
     * the connection configuration, and its own RedisQueue over that, mirroring
     * exactly what RedisServiceProvider and RedisConnector do. The application's
     * manager, its resolved connections and its configuration are all untouched:
     * a shared client whose read timeout we had lowered would start dropping
     * legitimate slow commands for the rest of the request.
     *
     * Deliberately narrow. Anything that is not a plain redis queue over a
     * plain phpredis/predis connection — sync, database and sqs installations,
     * clusters, a custom Redis driver registered with Redis::extend() — falls
     * back to the ordinary connection, unbounded but correct. A probe that
     * reports nothing on a working system is worse than a slow one.
     *
     * @param  string|null  $connection  The configured connection, or null for the default
     * @return array{0: \Illuminate\Contracts\Queue\Queue, 1: RedisManager|null}
     *                                                                           The queue to read the depth from, and the throwaway redis
     *                                                                           manager to close afterwards when the probe built one
     */
    protected function queueProbe(?string $connection): array
    {
        $name = $connection ?: config('queue.default');
        $queueConfig = config("queue.connections.{$name}");

        if (is_array($queueConfig) && ($queueConfig['driver'] ?? null) === 'redis') {
            $manager = $this->boundedRedisManager($queueConfig['connection'] ?? 'default');

            if ($manager !== null) {
                // Mirrors Illuminate\Queue\Connectors\RedisConnector::connect(),
                // so the depth is read exactly the way the application reads it.
                return [new RedisQueue(
                    $manager,
                    $queueConfig['queue'] ?? 'default',
                    $queueConfig['connection'] ?? 'default',
                    $queueConfig['retry_after'] ?? 60,
                    $queueConfig['block_for'] ?? null,
                    $queueConfig['after_commit'] ?? null,
                    $queueConfig['migration_batch_size'] ?? -1,
                ), $manager];
            }
        }

        return [Queue::connection($connection), null];
    }

    /**
     * A private RedisManager over a short-timeout copy of one connection.
     *
     * The connection keeps its own name so that options.parameters.<name> still
     * resolves the way it does for the application, and keeps its host, port,
     * credentials and database so the probe reaches the same server. Only the
     * timeouts move.
     *
     * Returns null — meaning "probe this the ordinary way" — for anything this
     * cannot faithfully rebuild: a cluster, a connection that is not an array,
     * or a client other than phpredis/predis, since a driver registered through
     * Redis::extend() lives on the application's manager and not on ours.
     *
     * @param  string  $name  The redis connection the queue reads through
     */
    protected function boundedRedisManager(string $name): ?RedisManager
    {
        $redis = config('database.redis', []);

        if (! is_array($redis) || ! is_array($redis[$name] ?? null)) {
            return null;
        }

        // RedisServiceProvider pulls 'client' out as the driver and passes the
        // rest as connections; 'options' is kept so prefixes and per-connection
        // parameters still apply.
        $client = $redis['client'] ?? 'phpredis';

        if (! in_array($client, ['phpredis', 'predis'], true)) {
            return null;
        }

        return new RedisManager(app(), $client, [
            'options' => $redis['options'] ?? [],
            // 'timeout' is the connect timeout for both clients. 'read_timeout'
            // is phpredis' name for the read bound, 'read_write_timeout' is
            // predis'; each client ignores the other's key, so setting both
            // bounds the probe whichever one is installed.
            $name => array_merge($redis[$name], [
                'timeout' => self::PROBE_TIMEOUT_SECONDS,
                'read_timeout' => self::PROBE_TIMEOUT_SECONDS,
                'read_write_timeout' => self::PROBE_TIMEOUT_SECONDS,
                // A retry loop would multiply the bound just set.
                'retry_interval' => 0,
            ]),
        ]);
    }

    /**
     * Close whatever socket the probe opened.
     *
     * Only ever handed the probe's own manager, never the application's: the
     * health page can be polled, and leaking one connection per load would be
     * a slower version of the outage it reports.
     */
    protected function closeProbeConnections(?RedisManager $manager): void
    {
        if ($manager === null) {
            return;
        }

        foreach ($manager->connections() ?? [] as $connection) {
            try {
                $connection->disconnect();
            } catch (\Throwable $e) {
                Log::warning('[Vanguard] The health probe could not close its redis connection', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
     * backup pipeline. The tenant enumeration, the landlord read and the
     * tenant aggregate are each guarded on their own, so a tenancy failure
     * still leaves the landlord row readable rather than emptying the whole
     * section.
     *
     * Two reads, whatever the customer list looks like. It used to be one
     * ORDER BY per target — the shape commit 334e4bb removed from /api/tenants
     * because the cost of the screen that says whether backups work grew with
     * the customer list. This is the landing page; two hundred tenants meant
     * two hundred round trips per load.
     *
     * @return array<string, mixed>
     */
    protected function freshness(): array
    {
        $threshold = 2 * VanguardScheduler::globalIntervalSeconds();
        $central = Vanguard::centralConnection();

        $tenantKeys = [];
        $reason = null;

        try {
            if ($this->tenancy->isEnabled()) {
                foreach ($this->tenancy->allTenants() as $tenant) {
                    $tenantKeys[] = (string) $tenant->getTenantKey();
                }
            }
        } catch (\Throwable $e) {
            // A tenancy failure should not cost us the landlord row: fall
            // back to it alone and report why the tenant list could not be
            // read.
            $tenantKeys = [];
            $reason = $e->getMessage();
        }

        $rows = [];

        try {
            // type = 'landlord', not merely "no tenant". A filesystem backup
            // also carries a null tenant_id, so selecting on that alone let a
            // manually triggered filesystem run turn this row green while the
            // central database had not been dumped in weeks — on the one
            // indicator that is supposed to notice.
            $rows[] = $this->freshnessRow(
                'landlord',
                null,
                BackupRecord::on($central)->completed()
                    ->whereNull('tenant_id')
                    ->where('type', 'landlord')
                    ->max('completed_at'),
                $threshold,
            );
        } catch (\Throwable $e) {
            // Unreadable, not absent: a row we could not read is dropped from
            // the list rather than faked, and the reason travels up so the
            // page still says why instead of just going blank.
            $reason = $e->getMessage();
        }

        if ($tenantKeys !== []) {
            try {
                $latest = BackupRecord::on($central)->completed()
                    ->whereIn('tenant_id', $tenantKeys)
                    ->groupBy('tenant_id')
                    ->selectRaw('tenant_id, MAX(completed_at) as last_success_at')
                    ->pluck('last_success_at', 'tenant_id');

                foreach ($tenantKeys as $key) {
                    $rows[] = $this->freshnessRow($key, $key, $latest->get($key), $threshold);
                }
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
            }
        }

        return [
            'threshold_seconds' => $threshold,
            'targets' => $rows,
            'reason' => $reason,
        ];
    }

    /**
     * Build one freshness row from a raw MAX(completed_at) value.
     *
     * The aggregate hands back whatever the driver stores rather than a cast
     * attribute, so the parse happens here — and an unparseable value means
     * "never", which the row already reports as stale.
     *
     * @param  mixed  $lastSuccess  Raw MAX(completed_at), or null when there is none
     * @return array<string, mixed>
     */
    protected function freshnessRow(string $label, ?string $tenantId, mixed $lastSuccess, int $threshold): array
    {
        $at = null;

        try {
            if ($lastSuccess instanceof \DateTimeInterface) {
                $at = Carbon::instance($lastSuccess);
            } elseif (is_string($lastSuccess) && $lastSuccess !== '') {
                $at = Carbon::parse($lastSuccess);
            }
        } catch (\Throwable) {
            $at = null;
        }

        $age = $at !== null ? (int) now()->diffInSeconds($at, true) : null;

        return [
            'target' => $label,
            'tenant_id' => $tenantId,
            'last_success_at' => $at?->toIso8601String(),
            'age_seconds' => $age,
            // Twice the interval, not once, so a run that slipped or a backup
            // that took an hour raises no false alarm. Never backed up counts
            // as stale: it is the worse case, not the unknown one.
            //
            // The comparison is strict, so exactly twice the threshold is still
            // fresh — the same inclusive boundary schedule() applies to the
            // heartbeat. The two say the same thing about the same instant.
            'stale' => $age === null || $age > $threshold,
        ];
    }
}
