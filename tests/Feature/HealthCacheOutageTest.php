<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The heartbeat is read out of the cache, and on this product the cache is
 * Redis. A Redis outage is precisely the moment somebody loads the health
 * page — and the page that reports breakage is the last thing allowed to
 * break. Every other section of the payload is guarded on its own; the store
 * read behind `schedule.last_seen_at` was the one left outside its try.
 */
class HealthCacheOutageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');
    }

    /**
     * Make the default cache store throw the way an unreachable Redis does.
     *
     * The rate limiter keeps its own store, so what breaks below is the
     * heartbeat read and nothing else — the throttle middleware in front of
     * the route still works.
     */
    protected function useUnreachableCache(): void
    {
        Cache::extend('exploding', fn ($app) => Cache::repository(new class implements Store
        {
            protected function down(): never
            {
                throw new RuntimeException('Connection refused [tcp://redis:6379]');
            }

            public function get($key)
            {
                $this->down();
            }

            public function many(array $keys)
            {
                $this->down();
            }

            public function put($key, $value, $seconds)
            {
                $this->down();
            }

            public function putMany(array $values, $seconds)
            {
                $this->down();
            }

            public function increment($key, $value = 1)
            {
                $this->down();
            }

            public function decrement($key, $value = 1)
            {
                $this->down();
            }

            public function forever($key, $value)
            {
                $this->down();
            }

            public function forget($key)
            {
                $this->down();
            }

            public function flush()
            {
                $this->down();
            }

            public function getPrefix()
            {
                return '';
            }
        }));

        config([
            'cache.stores.exploding' => ['driver' => 'exploding'],
            'cache.default' => 'exploding',
            'cache.limiter' => 'array',
        ]);
    }

    #[Test]
    public function an_unreachable_cache_reports_an_unknown_heartbeat_rather_than_throwing(): void
    {
        $this->useUnreachableCache();

        $this->assertNull(VanguardScheduler::lastSeenAt());
    }

    #[Test]
    public function the_health_page_still_answers_when_the_cache_is_down(): void
    {
        $this->useUnreachableCache();

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        // Unknown, not alive: no stamp could be read, which is the same answer
        // as no stamp having been written.
        $this->assertNull($response->json('schedule.last_seen_at'));
        $this->assertFalse($response->json('schedule.alive'));

        // And the rest of the payload is still there — one broken section may
        // not empty the page.
        $this->assertNotNull($response->json('destinations'));
        $this->assertNotNull($response->json('freshness'));
    }
}
