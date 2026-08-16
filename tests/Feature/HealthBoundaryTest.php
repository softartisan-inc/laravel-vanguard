<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The exact boundary of the "twice the interval" rule.
 *
 * Two sections of the health screen quote the same rule and used to resolve its
 * edge in opposite directions: a heartbeat landing exactly on twice the interval
 * was reported dead, a backup landing exactly on twice the threshold was
 * reported fresh. Whichever answer is right, one page cannot give both — an
 * operator reading a red cron next to a green freshness row on the same
 * timestamp has no way to tell which of the two is lying.
 *
 * The boundary is inclusive on both: at exactly twice the interval nothing has
 * been missed yet.
 */
class HealthBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);

        // Hourly, so the interval is a round 3600 seconds and the boundary is
        // a value a test can hit exactly.
        config(['vanguard.schedule.frequency' => 'hourly']);

        // Frozen, and on a whole second: without freezing, now() moves between
        // the moment the fixture is written and the moment the controller reads
        // it; and both stores involved keep whole seconds — the heartbeat is an
        // ISO-8601 string, completed_at a datetime column — so a frozen instant
        // carrying microseconds would come back rounded and land next to the
        // boundary rather than on it. Neither is the case under test.
        $this->travelTo(now()->startOfSecond());
    }

    private function interval(): int
    {
        return VanguardScheduler::globalIntervalSeconds();
    }

    // ─── The heartbeat ───────────────────────────────────────────

    #[Test]
    public function a_heartbeat_landing_exactly_on_twice_the_interval_is_still_alive(): void
    {
        Cache::put(
            VanguardScheduler::HEARTBEAT_KEY,
            now()->subSeconds(2 * $this->interval())->toIso8601String(),
            now()->addDays(2),
        );

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('schedule.alive', true);
    }

    #[Test]
    public function a_heartbeat_one_second_past_twice_the_interval_is_dead(): void
    {
        Cache::put(
            VanguardScheduler::HEARTBEAT_KEY,
            now()->subSeconds(2 * $this->interval() + 1)->toIso8601String(),
            now()->addDays(2),
        );

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('schedule.alive', false);
    }

    // ─── The freshness rows ──────────────────────────────────────

    #[Test]
    public function a_backup_landing_exactly_on_twice_the_threshold_is_still_fresh(): void
    {
        $this->makeRecord([
            'type' => 'landlord',
            'tenant_id' => null,
            'status' => 'completed',
            'completed_at' => now()->subSeconds(2 * $this->interval()),
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $landlord = collect($response->json('freshness.targets'))->firstWhere('target', 'landlord');

        $this->assertSame(2 * $this->interval(), $landlord['age_seconds']);
        $this->assertFalse($landlord['stale']);
    }

    #[Test]
    public function a_backup_one_second_past_twice_the_threshold_is_stale(): void
    {
        $this->makeRecord([
            'type' => 'landlord',
            'tenant_id' => null,
            'status' => 'completed',
            'completed_at' => now()->subSeconds(2 * $this->interval() + 1),
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $landlord = collect($response->json('freshness.targets'))->firstWhere('target', 'landlord');

        $this->assertTrue($landlord['stale']);
    }

    // ─── The two agree ───────────────────────────────────────────

    #[Test]
    public function the_cron_and_the_freshness_row_agree_on_the_same_timestamp(): void
    {
        // The same instant, exactly on the boundary, read by both sections.
        $boundary = now()->subSeconds(2 * $this->interval());

        Cache::put(VanguardScheduler::HEARTBEAT_KEY, $boundary->toIso8601String(), now()->addDays(2));

        $this->makeRecord([
            'type' => 'landlord',
            'tenant_id' => null,
            'status' => 'completed',
            'completed_at' => $boundary,
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $landlord = collect($response->json('freshness.targets'))->firstWhere('target', 'landlord');

        $this->assertSame(
            $response->json('schedule.alive'),
            ! $landlord['stale'],
            'the cron and the freshness row must resolve the same boundary the same way',
        );
    }

    #[Test]
    public function a_backup_that_never_ran_is_stale_whatever_the_boundary_says(): void
    {
        // No record at all: the boundary rule never gets to apply, and "never"
        // is the worse case rather than the unknown one.
        BackupRecord::query()->delete();

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $landlord = collect($response->json('freshness.targets'))->firstWhere('target', 'landlord');

        $this->assertNull($landlord['age_seconds']);
        $this->assertTrue($landlord['stale']);
    }
}
