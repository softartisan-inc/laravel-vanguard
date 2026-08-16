<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Console\VanguardScheduler;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class SchedulerTenantKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A tenant whose key is whatever the host application stores.
     */
    protected function tenant(string $key): object
    {
        return new class($key)
        {
            public function __construct(public string $key) {}

            public function getTenantKey(): string
            {
                return $this->key;
            }
        };
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function scheduleFor(array $keys): Schedule
    {
        config([
            'vanguard.schedule.enabled' => true,
            'vanguard.schedule.landlord' => false,
            'vanguard.schedule.tenants' => true,
            'vanguard.retention.enabled' => false,
        ]);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn(
            collect(array_map(fn (string $key) => $this->tenant($key), $keys)),
        );
        $tenancy->shouldReceive('tenantSchedule')->andReturn(null);

        $schedule = new Schedule;

        (new VanguardScheduler($tenancy))->schedule($schedule);

        return $schedule;
    }

    /**
     * @return array<int, string>
     */
    protected function backupCommands(Schedule $schedule): array
    {
        return collect($schedule->events())
            ->map(fn ($event) => $event->command)
            ->filter(fn ($command) => str_contains((string) $command, 'vanguard:backup'))
            ->values()
            ->all();
    }

    #[Test]
    public function it_schedules_a_tenant_whose_key_is_plain(): void
    {
        $commands = $this->backupCommands($this->scheduleFor(['9001', 'acme-corp', 'acme_corp.eu']));

        $this->assertCount(3, $commands);

        foreach (['9001', 'acme-corp', 'acme_corp.eu'] as $key) {
            $this->assertTrue(
                collect($commands)->contains(fn ($c) => str_contains($c, "--tenant={$key}")),
                "tenant [{$key}] is a perfectly ordinary key and must still be scheduled",
            );
        }
    }

    #[Test]
    public function it_refuses_to_interpolate_a_tenant_key_that_carries_shell_syntax(): void
    {
        // The key lands inside a command string the scheduler hands to the
        // shell. Anything outside [A-Za-z0-9_.-] is refused rather than
        // escaped: a tenant key is an identifier, and one that is not has no
        // business reaching a command line at all.
        $commands = $this->backupCommands($this->scheduleFor([
            '9001',
            '9002; rm -rf /',
            '$(id)',
            '`whoami`',
            "9003\nvanguard:prune",
            'a b',
        ]));

        $this->assertCount(1, $commands, 'only the plain key may be scheduled');
        $this->assertStringContainsString('--tenant=9001', $commands[0]);
    }

    #[Test]
    public function it_says_out_loud_which_tenant_it_skipped(): void
    {
        // A tenant silently dropped from the schedule is a tenant that stops
        // being backed up without anybody knowing — the March 2026 shape.
        $logger = Log::spy();

        $schedule = $this->scheduleFor(['9002; rm -rf /']);

        $this->assertSame([], $this->backupCommands($schedule), 'the tenant was indeed dropped');

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, '[Vanguard]')
                && str_contains($message, 'tenant key')
                && ($context['tenant_key'] ?? null) === '9002; rm -rf /',
        );
    }
}
