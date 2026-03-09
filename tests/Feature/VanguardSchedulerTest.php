<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class VanguardSchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Scheduling disabled
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function scheduler_registers_no_events_when_disabled(): void
    {
        config(['vanguard.schedule.enabled' => false]);

        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldNotReceive('command');

        $tenancy   = Mockery::mock(TenancyResolver::class);
        $scheduler = new VanguardScheduler($tenancy);

        $scheduler->schedule($schedule);

        // No assertion needed — Mockery will fail if 'command' is called
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // Scheduling enabled
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function scheduler_registers_landlord_command_when_enabled(): void
    {
        config([
            'vanguard.schedule.enabled'   => true,
            'vanguard.schedule.landlord'  => true,
            'vanguard.schedule.tenants'   => false,
            'vanguard.schedule.frequency' => 'daily',
            'vanguard.schedule.cron'      => '0 2 * * *',
            'vanguard.retention.enabled'  => false,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldNotReceive('isEnabled');

        $pendingMock = Mockery::mock();
        $pendingMock->shouldReceive('cron')->once()->with('0 2 * * *')->andReturnSelf();
        $pendingMock->shouldReceive('timezone')->once()->andReturnSelf();
        $pendingMock->shouldReceive('withoutOverlapping')->once()->andReturnSelf();
        $pendingMock->shouldReceive('runInBackground')->once()->andReturnSelf();
        $pendingMock->shouldReceive('onFailure')->once()->andReturnSelf();

        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('command')
            ->once()
            ->with('vanguard:backup --landlord')
            ->andReturn($pendingMock);

        $scheduler = new VanguardScheduler($tenancy);
        $scheduler->schedule($schedule);
        $this->addToAssertionCount(1); // Mockery expectations count as assertions
    }

    /** @test */
    public function scheduler_registers_per_tenant_commands(): void
    {
        config([
            'vanguard.schedule.enabled'   => true,
            'vanguard.schedule.landlord'  => false,
            'vanguard.schedule.tenants'   => true,
            'vanguard.schedule.frequency' => 'daily',
            'vanguard.schedule.cron'      => '0 2 * * *',
            'vanguard.retention.enabled'  => false,
        ]);

        $t1 = new class { public function getTenantKey() { return 'acme'; } };
        $t2 = new class { public function getTenantKey() { return 'globex'; } };

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->once()->andReturn(true);
        $tenancy->shouldReceive('allTenants')->once()->andReturn(collect([$t1, $t2]));
        $tenancy->shouldReceive('tenantSchedule')->twice()->andReturn(null);

        $pendingMock = Mockery::mock();
        $pendingMock->shouldReceive('cron')->twice()->andReturnSelf();
        $pendingMock->shouldReceive('timezone')->twice()->andReturnSelf();
        $pendingMock->shouldReceive('withoutOverlapping')->twice()->andReturnSelf();
        $pendingMock->shouldReceive('runInBackground')->twice()->andReturnSelf();
        $pendingMock->shouldReceive('onFailure')->twice()->andReturnSelf();

        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('command')
            ->with('vanguard:backup --tenant=acme')
            ->once()
            ->andReturn($pendingMock);
        $schedule->shouldReceive('command')
            ->with('vanguard:backup --tenant=globex')
            ->once()
            ->andReturn($pendingMock);

        $scheduler = new VanguardScheduler($tenancy);
        $scheduler->schedule($schedule);
        $this->addToAssertionCount(1); // Mockery expectations count as assertions
    }

    /** @test */
    public function scheduler_uses_tenant_custom_cron_when_set(): void
    {
        config([
            'vanguard.schedule.enabled'   => true,
            'vanguard.schedule.landlord'  => false,
            'vanguard.schedule.tenants'   => true,
            'vanguard.schedule.frequency' => 'daily',
            'vanguard.schedule.cron'      => '0 2 * * *',
            'vanguard.retention.enabled'  => false,
        ]);

        $tenant = new class { public function getTenantKey() { return 'vip'; } };

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn(collect([$tenant]));
        $tenancy->shouldReceive('tenantSchedule')->once()->andReturn('0 4 * * 0'); // weekly override

        $pendingMock = Mockery::mock();
        $pendingMock->shouldReceive('cron')
            ->once()
            ->with('0 4 * * 0')  // custom cron, not global
            ->andReturnSelf();
        $pendingMock->shouldReceive('timezone')->andReturnSelf();
        $pendingMock->shouldReceive('withoutOverlapping')->andReturnSelf();
        $pendingMock->shouldReceive('runInBackground')->andReturnSelf();
        $pendingMock->shouldReceive('onFailure')->andReturnSelf();

        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('command')
            ->with('vanguard:backup --tenant=vip')
            ->andReturn($pendingMock);

        $scheduler = new VanguardScheduler($tenancy);
        $scheduler->schedule($schedule);
        $this->addToAssertionCount(1); // Mockery expectations count as assertions
    }

    // ─────────────────────────────────────────────────────────────
    // Cron expression resolution
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function cron_expression_matches_frequency_setting(): void
    {
        $scheduler = new VanguardScheduler(Mockery::mock(TenancyResolver::class));
        $method    = new \ReflectionMethod(VanguardScheduler::class, 'globalCron');
        $method->setAccessible(true);

        config(['vanguard.schedule.frequency' => 'hourly']);
        $this->assertSame('0 * * * *', $method->invoke($scheduler));

        config(['vanguard.schedule.frequency' => 'weekly']);
        $this->assertSame('0 2 * * 0', $method->invoke($scheduler));

        config(['vanguard.schedule.frequency' => 'monthly']);
        $this->assertSame('0 2 1 * *', $method->invoke($scheduler));

        config(['vanguard.schedule.frequency' => 'custom', 'vanguard.schedule.cron' => '*/15 * * * *']);
        $this->assertSame('*/15 * * * *', $method->invoke($scheduler));
    }

    /** @test */
    public function retention_prune_is_registered_when_enabled(): void
    {
        config([
            'vanguard.schedule.enabled'  => true,
            'vanguard.schedule.landlord' => false,
            'vanguard.schedule.tenants'  => false,
            'vanguard.retention.enabled' => true,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);

        $pendingMock = Mockery::mock();
        $pendingMock->shouldReceive('daily')->once()->andReturnSelf();
        $pendingMock->shouldReceive('timezone')->once()->andReturnSelf();
        $pendingMock->shouldReceive('withoutOverlapping')->once()->andReturnSelf();
        $pendingMock->shouldReceive('runInBackground')->once()->andReturnSelf();

        $schedule = Mockery::mock(Schedule::class);
        $schedule->shouldReceive('command')
            ->once()
            ->with('vanguard:prune')
            ->andReturn($pendingMock);

        $scheduler = new VanguardScheduler($tenancy);
        $scheduler->schedule($schedule);
        $this->addToAssertionCount(1); // Mockery expectations count as assertions
    }
}
