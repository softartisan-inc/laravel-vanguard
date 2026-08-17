<?php

namespace SoftArtisan\Vanguard\Http\Concerns;

use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Reading the depth of Vanguard's queue without hanging on it.
 *
 * Lifted out of HealthController when the operations screen needed the same
 * answer: how many jobs are waiting, or why that cannot be known. Two copies
 * of a bounded Redis probe is two places to get the bound wrong, and the
 * screens would then disagree about whether a worker exists — the one
 * question both of them are for.
 */
trait ProbesQueueDepth
{
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
    protected function queueSnapshot(): array
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
}
