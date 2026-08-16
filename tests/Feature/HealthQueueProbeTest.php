<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Http\Controllers\HealthController;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The queue depth read must be bounded — without ever costing the true depth.
 *
 * A Redis that is routable but silent does not throw: the client sits in its
 * connect timeout, and the try/catch around the depth read cannot help because
 * nothing has been raised yet. An auditor pointing vanguard.queue.connection at
 * such a host waited more than sixty seconds for the page whose whole purpose is
 * to answer "is it working" — a worse failure than a 500, because the operator
 * gets nothing at all and a monitoring probe times out without a payload.
 *
 * The first attempt at bounding it wrote a probe connection into the config
 * repository, which does nothing: RedisManager is constructed with a snapshot of
 * database.redis, so the probe connection did not exist as far as the manager was
 * concerned and a perfectly healthy Redis reported "not configured" with a null
 * depth. A wrong number is worse than a slow one, so what these tests pin down
 * is both halves: the probe reads through a bounded connection, *and* it reads
 * through one the manager can actually resolve.
 *
 * The suite has no Redis, so the wall clock and the live depth are proven
 * against a real installation instead — see the audit fix report.
 */
class HealthQueueProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);
    }

    /**
     * Point Vanguard at a redis-backed queue whose Redis would hang forever.
     *
     * 30 seconds of connect timeout and 60 of read timeout is not an exotic
     * configuration — it is close to what a stock Laravel installation carries
     * once someone raises it for a slow link.
     */
    protected function configureSlowRedisQueue(string $client = 'predis'): void
    {
        config([
            'database.redis.client' => $client,
            'database.redis.options' => ['prefix' => 'vg_'],
            'database.redis.vanguard-audit' => [
                'host' => '10.255.255.1',
                'port' => 6379,
                'database' => 0,
                'timeout' => 30.0,
                'read_timeout' => 60.0,
            ],
            'queue.connections.vanguard-audit' => [
                'driver' => 'redis',
                'connection' => 'vanguard-audit',
                'queue' => 'vanguard',
                'retry_after' => 90,
            ],
            'vanguard.queue.connection' => 'vanguard-audit',
            'vanguard.queue.queue' => 'vanguard',
        ]);
    }

    /**
     * The queue the controller would read the depth from, and the throwaway
     * redis manager behind it.
     *
     * @return array{0: mixed, 1: RedisManager|null}
     */
    protected function probeFor(?string $connection): array
    {
        $controller = app(HealthController::class);

        $probe = new \ReflectionMethod($controller, 'queueProbe');

        return $probe->invoke($controller, $connection);
    }

    /**
     * Read the configuration a RedisManager was actually constructed with —
     * which is the only configuration it will ever resolve a connection from.
     *
     * @return array<string, mixed>
     */
    protected function managerConfig(RedisManager $manager): array
    {
        $config = new ReflectionProperty($manager, 'config');

        return $config->getValue($manager);
    }

    #[Test]
    public function the_queue_depth_read_is_bounded_by_a_short_connect_timeout(): void
    {
        $this->configureSlowRedisQueue();

        [$queue, $manager] = $this->probeFor('vanguard-audit');

        $this->assertInstanceOf(RedisQueue::class, $queue);
        $this->assertInstanceOf(RedisManager::class, $manager);

        $bounded = $this->managerConfig($manager)['vanguard-audit'];

        $this->assertLessThanOrEqual(2.0, (float) $bounded['timeout'], 'the connect timeout must be seconds, not a minute');
        $this->assertGreaterThan(0.0, (float) $bounded['timeout'], 'a zero connect timeout means "wait forever"');
        $this->assertLessThanOrEqual(2.0, (float) $bounded['read_timeout'], 'phpredis read bound must be seconds');
        $this->assertLessThanOrEqual(2.0, (float) $bounded['read_write_timeout'], 'predis read bound must be seconds');
    }

    #[Test]
    public function the_bounded_connection_is_one_the_manager_can_actually_resolve(): void
    {
        // The regression that made this file necessary: a probe connection that
        // only existed in the config repository was invisible to RedisManager,
        // so a healthy Redis answered "not configured" with a null depth.
        $this->configureSlowRedisQueue();

        [$queue, $manager] = $this->probeFor('vanguard-audit');

        $config = $this->managerConfig($manager);

        $connectionName = new ReflectionProperty($queue, 'connection');

        $this->assertArrayHasKey(
            $connectionName->getValue($queue),
            $config,
            'the queue must read through a connection the probe manager was constructed with',
        );
    }

    #[Test]
    public function the_probe_keeps_the_host_credentials_and_options_of_the_real_target(): void
    {
        // Only the timeouts move. A rehearsal against a different server would
        // prove nothing about the one the application actually dispatches to.
        $this->configureSlowRedisQueue();

        [, $manager] = $this->probeFor('vanguard-audit');

        $config = $this->managerConfig($manager);

        $this->assertSame('10.255.255.1', $config['vanguard-audit']['host']);
        $this->assertSame(6379, $config['vanguard-audit']['port']);
        $this->assertSame(0, $config['vanguard-audit']['database']);
        $this->assertSame(['prefix' => 'vg_'], $config['options']);
    }

    #[Test]
    public function bounding_the_probe_does_not_touch_the_application_redis(): void
    {
        $this->configureSlowRedisQueue();

        [, $manager] = $this->probeFor('vanguard-audit');

        $this->assertNotSame(app('redis'), $manager, 'the probe must not reconfigure the shared manager');

        $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertSame(30.0, config('database.redis.vanguard-audit.timeout'));
        $this->assertSame(60.0, config('database.redis.vanguard-audit.read_timeout'));
        $this->assertSame('vanguard-audit', config('queue.connections.vanguard-audit.connection'));
        $this->assertArrayNotHasKey('vanguard-health-probe', config('database.redis'));
    }

    #[Test]
    public function an_unreachable_redis_reports_unknown_with_the_real_failure(): void
    {
        // Bounded, but still honest: pending stays null and the reason names
        // what actually went wrong rather than a self-inflicted misconfiguration.
        $this->configureSlowRedisQueue();

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertNull($response->json('queue.pending'));
        $this->assertNotEmpty($response->json('queue.reason'));
        $this->assertStringNotContainsStringIgnoringCase(
            'not configured',
            (string) $response->json('queue.reason'),
            'a probe that cannot find its own connection is reporting its own bug, not the outage',
        );
    }

    #[Test]
    public function a_queue_that_is_not_redis_is_probed_exactly_as_configured(): void
    {
        // sync, database and sqs installations exist: the probe has nothing to
        // bound there and must leave the connection alone rather than invent one.
        config([
            'queue.connections.vanguard-db' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'vanguard',
            ],
            'vanguard.queue.connection' => 'vanguard-db',
        ]);

        [$queue, $manager] = $this->probeFor('vanguard-db');

        $this->assertNull($manager, 'a non-redis driver must not get a throwaway redis manager');
        $this->assertSame(Queue::connection('vanguard-db'), $queue);
    }

    #[Test]
    public function a_custom_redis_client_falls_back_to_the_ordinary_connection(): void
    {
        // A driver registered with Redis::extend() lives on the application's
        // manager, not on ours. Unbounded but correct beats bounded but broken.
        $this->configureSlowRedisQueue(client: 'some-custom-client');

        [$queue, $manager] = $this->probeFor('vanguard-audit');

        $this->assertNull($manager, 'no throwaway manager can be built for a driver we cannot construct');
        $this->assertSame(
            Queue::connection('vanguard-audit'),
            $queue,
            'the probe must fall back to the application own connection, unbounded but correct',
        );
    }

    #[Test]
    public function a_redis_connection_that_does_not_exist_falls_back_rather_than_inventing_one(): void
    {
        config([
            'queue.connections.vanguard-audit' => [
                'driver' => 'redis',
                'connection' => 'no-such-redis-connection',
                'queue' => 'vanguard',
            ],
        ]);

        [, $manager] = $this->probeFor('vanguard-audit');

        $this->assertNull($manager);
    }

    #[Test]
    public function the_probe_reports_the_depth_the_queue_answers_with(): void
    {
        // The live depth is proven against a real Redis in the audit fix report;
        // what is pinned here is that whatever the queue answers reaches the
        // payload, with no reason attached.
        config(['vanguard.queue.connection' => 'vanguard-fake', 'vanguard.queue.queue' => 'vanguard']);

        Queue::shouldReceive('connection')->once()->andReturn(new class
        {
            public function size($queue = null): int
            {
                return 10;
            }
        });

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('queue.pending', 10)
            ->assertJsonPath('queue.reason', null)
            ->assertJsonPath('queue.connection', 'vanguard-fake');
    }
}
