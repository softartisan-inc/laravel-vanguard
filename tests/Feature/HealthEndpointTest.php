<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);
    }

    // ─── Destinations ────────────────────────────────────────────

    #[Test]
    public function it_proves_a_destination_is_writable_by_writing_to_it(): void
    {
        // "The disk is configured" is exactly what the dashboard said for five
        // months while every write failed. Only a round trip answers this.
        Storage::fake('local');

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('destinations.0.name', 'local')
            ->assertJsonPath('destinations.0.enabled', true)
            ->assertJsonPath('destinations.0.writable', true)
            ->assertJsonPath('destinations.0.reason', null);
    }

    #[Test]
    public function the_write_probe_leaves_nothing_behind(): void
    {
        Storage::fake('local');

        $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
            'the witness object must be deleted, not accumulate one per page load',
        );
    }

    #[Test]
    public function a_destination_that_cannot_be_written_says_so_with_a_reason(): void
    {
        config([
            'vanguard.destinations.remote.enabled' => true,
            'vanguard.destinations.remote.disk' => 'unwritable',
            // A path under a non-directory: mkdir fails on any POSIX system,
            // and 'throw' makes Flysystem raise rather than return false.
            'filesystems.disks.unwritable' => [
                'driver' => 'local',
                'root' => '/dev/null/vanguard-cannot-write',
                'throw' => true,
            ],
        ]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $remote = collect($response->json('destinations'))->firstWhere('name', 'remote');

        $this->assertFalse($remote['writable']);
        $this->assertNotEmpty($remote['reason'], 'a red line without a reason is not actionable');
    }

    #[Test]
    public function a_disabled_destination_is_reported_but_not_probed(): void
    {
        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $ftp = collect($response->json('destinations'))->firstWhere('name', 'ftp');

        $this->assertFalse($ftp['enabled']);
        $this->assertNull($ftp['writable'], 'unknown, not false: nothing was tried');
    }

    // ─── Alerts ──────────────────────────────────────────────────

    #[Test]
    public function no_secret_value_ever_reaches_the_payload(): void
    {
        config([
            'vanguard.notifications.mail.to' => 'ops-secret@in-immo.app',
            'vanguard.notifications.slack.webhook_url' => 'https://hooks.slack.com/services/T00/B00/xoxb-do-not-leak',
        ]);

        $payload = $this->getJson('/vanguard/api/health')->assertOk()->getContent();

        $this->assertStringNotContainsString('ops-secret@in-immo.app', $payload);
        $this->assertStringNotContainsString('hooks.slack.com', $payload);
        $this->assertStringNotContainsString('xoxb-do-not-leak', $payload);

        $decoded = json_decode($payload, true);

        $this->assertSame('set', $decoded['alerts']['mail']);
        $this->assertSame('set', $decoded['alerts']['slack']);
    }

    #[Test]
    public function an_unconfigured_alert_reads_absent(): void
    {
        config([
            'vanguard.notifications.mail.to' => null,
            'vanguard.notifications.slack.webhook_url' => null,
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.on_success' => false,
        ]);

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('alerts.mail', 'absent')
            ->assertJsonPath('alerts.slack', 'absent')
            ->assertJsonPath('alerts.on_failure', true)
            ->assertJsonPath('alerts.on_success', false);
    }

    // ─── Schedule ────────────────────────────────────────────────

    #[Test]
    public function a_cron_that_never_ran_is_reported_dead(): void
    {
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('schedule.last_seen_at', null)
            ->assertJsonPath('schedule.alive', false);
    }

    #[Test]
    public function a_cron_seen_recently_is_reported_alive(): void
    {
        VanguardScheduler::heartbeat();

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('schedule.alive', true);
    }

    #[Test]
    public function a_stamp_older_than_twice_the_interval_is_reported_dead(): void
    {
        config(['vanguard.schedule.frequency' => 'daily', 'vanguard.schedule.cron' => '0 2 * * *']);

        Cache::put(
            VanguardScheduler::HEARTBEAT_KEY,
            now()->subDays(3)->toIso8601String(),
            now()->addDays(2),
        );

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('schedule.alive', false)
            ->assertJsonPath('schedule.interval_seconds', 86400);
    }

    // ─── Queue ───────────────────────────────────────────────────

    #[Test]
    public function it_reports_the_queue_it_would_dispatch_to(): void
    {
        config([
            'vanguard.queue.enabled' => true,
            'vanguard.queue.queue' => 'vanguard',
            'vanguard.queue.timeout' => 3600,
        ]);

        $this->getJson('/vanguard/api/health')->assertOk()
            ->assertJsonPath('queue.enabled', true)
            ->assertJsonPath('queue.queue', 'vanguard')
            ->assertJsonPath('queue.timeout', 3600)
            ->assertJsonStructure(['queue' => ['enabled', 'connection', 'queue', 'pending', 'reason', 'timeout']]);
    }

    #[Test]
    public function an_unreachable_queue_reports_unknown_rather_than_zero(): void
    {
        // "No jobs waiting" and "the driver is down" look identical to a
        // caller who is handed a 0 either way.
        config(['vanguard.queue.connection' => 'no-such-connection']);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertNull($response->json('queue.pending'));
        $this->assertNotEmpty($response->json('queue.reason'));
    }

    // ─── Freshness ───────────────────────────────────────────────

    #[Test]
    public function a_recent_landlord_backup_is_fresh(): void
    {
        config(['vanguard.schedule.frequency' => 'daily', 'vanguard.schedule.cron' => '0 2 * * *']);

        $this->makeRecord(['type' => 'landlord', 'status' => 'completed', 'completed_at' => now()->subHour()]);

        $response = $this->getJson('/vanguard/api/health')->assertOk();

        $this->assertSame(172800, $response->json('freshness.threshold_seconds'), 'twice the daily interval');

        $landlord = collect($response->json('freshness.targets'))->firstWhere('target', 'landlord');

        $this->assertFalse($landlord['stale']);
        $this->assertNotNull($landlord['last_success_at']);
        $this->assertLessThan(4000, $landlord['age_seconds']);
    }

    #[Test]
    public function a_backup_older_than_twice_the_interval_is_stale(): void
    {
        // Twice, not once: a run that slipped or a backup that took an hour
        // must not raise a false alarm (spec §5).
        config(['vanguard.schedule.frequency' => 'daily', 'vanguard.schedule.cron' => '0 2 * * *']);

        $this->makeRecord(['type' => 'landlord', 'status' => 'completed', 'completed_at' => now()->subHours(30)]);

        $landlord = collect($this->getJson('/vanguard/api/health')->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertFalse($landlord['stale'], '30 hours is under the 48-hour threshold');

        $this->makeRecord(['type' => 'landlord', 'status' => 'completed', 'completed_at' => now()->subHours(60)]);
        BackupRecord::query()->update(['completed_at' => now()->subHours(60)]);

        $landlord = collect($this->getJson('/vanguard/api/health')->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertTrue($landlord['stale']);
    }

    #[Test]
    public function a_target_that_was_never_backed_up_is_stale(): void
    {
        // The one indicator that turns red on its own when nothing runs.
        $landlord = collect($this->getJson('/vanguard/api/health')->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertTrue($landlord['stale']);
        $this->assertNull($landlord['last_success_at']);
        $this->assertNull($landlord['age_seconds']);
    }

    #[Test]
    public function a_failed_backup_does_not_count_as_freshness(): void
    {
        $this->makeRecord(['type' => 'landlord', 'status' => 'failed', 'completed_at' => now()]);

        $landlord = collect($this->getJson('/vanguard/api/health')->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertTrue($landlord['stale'], 'a backup that failed backed nothing up');
    }
}
