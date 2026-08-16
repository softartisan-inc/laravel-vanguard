<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function nothing_is_seen_before_the_cron_has_ever_run(): void
    {
        // The March 2026 shape: a perfect configuration and no execution.
        // Absence has to be readable, not indistinguishable from silence.
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);

        $this->assertNull(VanguardScheduler::lastSeenAt());
    }

    #[Test]
    public function every_scheduled_command_stamps_the_heartbeat_when_it_runs(): void
    {
        config([
            'vanguard.schedule.enabled' => true,
            'vanguard.schedule.landlord' => true,
            'vanguard.schedule.tenants' => false,
            'vanguard.retention.enabled' => true,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(false);

        $schedule = new Schedule;
        (new VanguardScheduler($tenancy))->schedule($schedule);

        $events = $schedule->events();

        $this->assertCount(3, $events, 'landlord backup, prune, cleanup-tmp');

        foreach ($events as $event) {
            Cache::forget(VanguardScheduler::HEARTBEAT_KEY);

            // The before-callbacks run in the scheduler process, which is
            // exactly the process whose existence proves cron is alive —
            // runInBackground() spawns the work elsewhere.
            $event->callBeforeCallbacks($this->app);

            $this->assertNotNull(
                VanguardScheduler::lastSeenAt(),
                "[{$event->command}] runs without leaving any proof the cron is alive",
            );
        }
    }

    #[Test]
    public function the_stamp_carries_the_moment_it_was_written(): void
    {
        Cache::forget(VanguardScheduler::HEARTBEAT_KEY);

        $this->travelTo(now()->setSeconds(0));

        VanguardScheduler::heartbeat();

        $this->assertSame(
            now()->toIso8601String(),
            VanguardScheduler::lastSeenAt()->toIso8601String(),
        );

        $this->travelBack();
    }

    #[Test]
    public function the_global_interval_follows_the_configured_frequency(): void
    {
        config(['vanguard.schedule.frequency' => 'hourly']);
        $this->assertSame(3600, VanguardScheduler::globalIntervalSeconds());

        config(['vanguard.schedule.frequency' => 'daily', 'vanguard.schedule.cron' => '0 2 * * *']);
        $this->assertSame(86400, VanguardScheduler::globalIntervalSeconds());
    }

    #[Test]
    public function a_custom_cron_gets_its_real_interval_rather_than_a_guess(): void
    {
        // The spec's own example: four runs a day is a six-hour interval, so
        // the freshness threshold is twelve hours — not the twenty-four a
        // "custom means daily" shortcut would have produced.
        config([
            'vanguard.schedule.frequency' => 'custom',
            'vanguard.schedule.cron' => '0 0,6,12,18 * * *',
        ]);

        $this->assertSame(21600, VanguardScheduler::globalIntervalSeconds());
    }

    #[Test]
    public function an_unparseable_cron_falls_back_to_a_day_rather_than_throwing(): void
    {
        // A bad expression must not take the health endpoint down with it:
        // the screen that reports breakage is the last thing allowed to break.
        config([
            'vanguard.schedule.frequency' => 'custom',
            'vanguard.schedule.cron' => 'not a cron expression',
        ]);

        $this->assertSame(86400, VanguardScheduler::globalIntervalSeconds());
    }
}
