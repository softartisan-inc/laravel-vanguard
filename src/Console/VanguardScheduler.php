<?php

namespace SoftArtisan\Vanguard\Console;

use Illuminate\Console\Scheduling\Schedule;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class VanguardScheduler
{
    public function __construct(protected TenancyResolver $tenancy) {}

    public function schedule(Schedule $schedule): void
    {
        if (! config('vanguard.schedule.enabled', true)) {
            return;
        }

        $tz = config('vanguard.schedule.timezone', config('app.timezone', 'UTC'));

        // ─── Landlord backup ──────────────────────────────────────
        if (config('vanguard.schedule.landlord', true)) {
            $this->scheduleCommand($schedule, 'vanguard:backup --landlord', $this->globalCron(), $tz);
        }

        // ─── Tenant backups ───────────────────────────────────────
        if (config('vanguard.schedule.tenants', true) && $this->tenancy->isEnabled()) {
            foreach ($this->tenancy->allTenants() as $tenant) {
                $cron = $this->tenancy->tenantSchedule($tenant) ?? $this->globalCron();

                $this->scheduleCommand(
                    $schedule,
                    "vanguard:backup --tenant={$tenant->getTenantKey()}",
                    $cron,
                    $tz,
                );
            }
        }

        // ─── Auto-prune ───────────────────────────────────────────
        if (config('vanguard.retention.enabled', true)) {
            $schedule->command('vanguard:prune')
                ->daily()
                ->timezone($tz)
                ->withoutOverlapping()
                ->runInBackground();
        }
    }

    protected function scheduleCommand(Schedule $schedule, string $command, string $cron, string $tz): void
    {
        $schedule->command($command)
            ->cron($cron)
            ->timezone($tz)
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () use ($command) {
                \Log::error("[Vanguard] Scheduled command failed: {$command}");
            });
    }

    protected function globalCron(): string
    {
        $frequency = config('vanguard.schedule.frequency', 'daily');

        return match ($frequency) {
            'hourly'  => '0 * * * *',
            'daily'   => config('vanguard.schedule.cron', '0 2 * * *'),
            'weekly'  => '0 2 * * 0',
            'monthly' => '0 2 1 * *',
            'custom'  => config('vanguard.schedule.cron', '0 2 * * *'),
            default   => '0 2 * * *',
        };
    }
}
