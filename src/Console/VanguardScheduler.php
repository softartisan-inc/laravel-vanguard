<?php

namespace SoftArtisan\Vanguard\Console;

use Illuminate\Console\Scheduling\Schedule;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class VanguardScheduler
{
    /**
     * @param  TenancyResolver  $tenancy
     */
    public function __construct(protected TenancyResolver $tenancy) {}

    /**
     * Register all Vanguard scheduled commands with the Laravel scheduler.
     *
     * Reads vanguard.schedule config to determine what to schedule.
     * Does nothing when scheduling is disabled (vanguard.schedule.enabled = false).
     *
     * @param  Schedule  $schedule
     */
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

    /**
     * Register a single Artisan command on the scheduler with shared safety settings.
     *
     * All scheduled backup commands run in the background and use withoutOverlapping()
     * to prevent concurrent executions.
     *
     * @param  Schedule  $schedule
     * @param  string    $command  Artisan command string (e.g. 'vanguard:backup --landlord')
     * @param  string    $cron     Cron expression (e.g. '0 2 * * *')
     * @param  string    $tz       Timezone identifier (e.g. 'Europe/Paris')
     */
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

    /**
     * Resolve the global backup cron expression from the configured schedule frequency.
     *
     * Frequency maps: hourly → every hour, daily → 02:00, weekly → Sunday 02:00,
     * monthly → 1st of the month 02:00, custom → vanguard.schedule.cron value.
     *
     * @return string  A valid cron expression
     */
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
