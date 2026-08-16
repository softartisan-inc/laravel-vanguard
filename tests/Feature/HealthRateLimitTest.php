<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * Laravel keys a named rate limiter by limiter name plus user, not by route,
 * so every route wearing `throttle:vanguard.run` drew on one 5-per-minute
 * bucket: the backup trigger, the download — and the health page. Five loads
 * of the landing page therefore 429'd the trigger, the download, and the page
 * that reports breakage, blocked by having been read.
 */
class HealthRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');
    }

    #[Test]
    public function the_health_page_is_not_locked_out_by_its_own_loads(): void
    {
        // Six, one past the heavy-operations limit of five. A probe hitting
        // the landing page must not be rationed like a restore.
        for ($i = 1; $i <= 6; $i++) {
            $this->getJson('/vanguard/api/health')
                ->assertOk("health load #{$i} was refused: the page that reports breakage is rate-limited by being read");
        }
    }

    #[Test]
    public function reading_the_health_page_does_not_spend_the_backup_triggers_budget(): void
    {
        Queue::fake();
        config(['vanguard.queue.enabled' => true]);

        for ($i = 1; $i <= 5; $i++) {
            $this->getJson('/vanguard/api/health')->assertOk();
        }

        $this->postJson('/vanguard/api/backups/run', ['type' => 'landlord'])
            ->assertOk()
            ->assertJsonPath('queued', true);
    }

    #[Test]
    public function the_health_limiter_still_has_a_ceiling(): void
    {
        // Its own bucket, not no bucket: the endpoint writes and deletes an
        // object on every enabled destination, so an open dashboard tab must
        // not be able to hammer the bucket once a second.
        config(['vanguard.rate_limits.health' => 12]);

        $statuses = [];

        for ($i = 1; $i <= 14; $i++) {
            $statuses[] = $this->getJson('/vanguard/api/health')->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the health limiter must refuse something eventually');
        $this->assertSame(200, $statuses[11], 'the twelfth load is still within the configured limit');
    }
}
