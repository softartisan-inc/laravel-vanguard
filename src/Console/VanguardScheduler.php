<?php

namespace SoftArtisan\Vanguard\Console;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class VanguardScheduler
{
    /**
     * Where the scheduler proves it is alive.
     *
     * Nothing in the installation could distinguish a live cron from a dead
     * one. In March 2026 this product showed a flawless configuration and
     * backed up nothing for five months. A configuration is a claim; this
     * stamp is the only evidence.
     */
    public const HEARTBEAT_KEY = 'vanguard:scheduler:seen';

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
            $this->scheduleCommand($schedule, 'vanguard:backup --landlord', static::globalCron(), $tz);
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

                $cron = $this->tenancy->tenantSchedule($tenant) ?? static::globalCron();

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
                ->runInBackground()
                ->before(fn () => static::heartbeat());
        }

        // ─── Orphaned tmp cleanup ──────────────────────────────────
        // Removes session tmp dirs left by crashed workers (older than 6 hours).
        // Hourly, so this is the command that keeps the heartbeat fresh between
        // two daily backups.
        $schedule->command('vanguard:cleanup-tmp')
            ->hourly()
            ->timezone($tz)
            ->withoutOverlapping()
            ->runInBackground()
            ->before(fn () => static::heartbeat());
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
            // Stamped in the scheduler process itself, before the background
            // task is spawned: it is the scheduler running that we are trying
            // to prove, not the command succeeding.
            ->before(fn () => static::heartbeat())
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
    public static function globalCron(): string
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

    /**
     * Record that the scheduler ran.
     *
     * The TTL is two days: longer than any interval a Vanguard command uses,
     * so a stamp that stops being refreshed expires instead of lingering
     * forever and reporting a cron that died last month as alive.
     */
    public static function heartbeat(): void
    {
        Cache::put(static::HEARTBEAT_KEY, Carbon::now()->toIso8601String(), Carbon::now()->addDays(2));
    }

    /**
     * When the scheduler was last seen, or null if it never has been.
     */
    public static function lastSeenAt(): ?Carbon
    {
        try {
            $seen = Cache::get(static::HEARTBEAT_KEY);

            if (! is_string($seen) || $seen === '') {
                return null;
            }

            return Carbon::parse($seen);
        } catch (\Throwable) {
            // A corrupted cache entry — or a store that cannot be reached at
            // all — means "unknown", not "crash the health screen". On this
            // product the cache is Redis, and a Redis outage is precisely when
            // someone loads the page that reports breakage: it is the last
            // thing allowed to break. The store read used to sit outside this
            // try while every other section of the payload was guarded.
            return null;
        }
    }

    /**
     * How many seconds apart the global backup cron runs.
     *
     * The base for the freshness threshold on the health screen, which is
     * twice this (spec §5).
     *
     * The gap is measured between the next two runs from now. For an
     * irregular expression — '0 0,1 * * *', one hour then twenty-three — the
     * answer depends on when it is asked; that is a deliberate simplification,
     * since the alternative is to scan a whole period to report a threshold
     * that only has to be roughly right.
     */
    public static function globalIntervalSeconds(): int
    {
        try {
            $cron = new CronExpression(static::globalCron());

            $next = Carbon::instance($cron->getNextRunDate(Carbon::now()));
            $after = Carbon::instance($cron->getNextRunDate($next));

            return max(60, (int) $next->diffInSeconds($after, true));
        } catch (\Throwable) {
            // An unparseable expression is a configuration bug the health
            // screen should report, not one it should die of.
            return 86400;
        }
    }
}
