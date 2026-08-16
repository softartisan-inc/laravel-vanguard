<?php

namespace SoftArtisan\Vanguard\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class VanguardScheduler
{
    public function __construct(protected TenancyResolver $tenancy) {}

    /**
     * Register all Vanguard scheduled commands with the Laravel scheduler.
     *
     * Reads vanguard.schedule config to determine what to schedule.
     * Does nothing when scheduling is disabled (vanguard.schedule.enabled = false).
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
                $tenantKey = (string) $tenant->getTenantKey();

                if (! $this->isSafeTenantKey($tenantKey)) {
                    Log::warning(
                        '[Vanguard] Skipped a scheduled backup: the tenant key is not a plain identifier '
                        .'and cannot be interpolated into a command string.',
                        ['tenant_key' => $tenantKey],
                    );

                    continue;
                }

                $cron = $this->tenancy->tenantSchedule($tenant) ?? $this->globalCron();

                $this->scheduleCommand(
                    $schedule,
                    "vanguard:backup --tenant={$tenantKey}",
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

        // ─── Orphaned tmp cleanup ──────────────────────────────────
        // Removes session tmp dirs left by crashed workers (older than 6 hours).
        $schedule->command('vanguard:cleanup-tmp')
            ->hourly()
            ->timezone($tz)
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register a single Artisan command on the scheduler with shared safety settings.
     *
     * All scheduled backup commands run in the background and use withoutOverlapping()
     * to prevent concurrent executions.
     *
     * @param  string  $command  Artisan command string (e.g. 'vanguard:backup --landlord')
     * @param  string  $cron  Cron expression (e.g. '0 2 * * *')
     * @param  string  $tz  Timezone identifier (e.g. 'Europe/Paris')
     */
    protected function scheduleCommand(Schedule $schedule, string $command, string $cron, string $tz): void
    {
        $schedule->command($command)
            ->cron($cron)
            ->timezone($tz)
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () use ($command) {
                Log::error("[Vanguard] Scheduled command failed: {$command}");
            });
    }

    /**
     * Whether a tenant key may be interpolated into a scheduled command string.
     *
     * The key ends up inside a string the scheduler hands to a shell. An
     * allowlist rather than escaping: a tenant key is an identifier, and one
     * carrying a space, a newline or a shell metacharacter has no business on
     * a command line — refusing it is the honest answer, quoting it would only
     * hide how odd it is.
     *
     * @param  string  $key  The value returned by the tenant's getTenantKey()
     */
    protected function isSafeTenantKey(string $key): bool
    {
        return $key !== '' && preg_match('/^[A-Za-z0-9_.\-]+$/', $key) === 1;
    }

    /**
     * Resolve the global backup cron expression from the configured schedule frequency.
     *
     * Frequency maps: hourly → every hour, daily → 02:00, weekly → Sunday 02:00,
     * monthly → 1st of the month 02:00, custom → vanguard.schedule.cron value.
     *
     * @return string A valid cron expression
     */
    protected function globalCron(): string
    {
        $frequency = config('vanguard.schedule.frequency', 'daily');

        return match ($frequency) {
            'hourly' => '0 * * * *',
            'daily' => config('vanguard.schedule.cron', '0 2 * * *'),
            'weekly' => '0 2 * * 0',
            'monthly' => '0 2 1 * *',
            'custom' => config('vanguard.schedule.cron', '0 2 * * *'),
            default => '0 2 * * *',
        };
    }
}
