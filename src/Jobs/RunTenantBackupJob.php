<?php

namespace SoftArtisan\Vanguard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class RunTenantBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout read from config at dispatch time so it can be overridden per environment.
     * Falls back to 3600 s (1 hour) if the config key is absent.
     */
    public int $timeout;

    /**
     * Three attempts: handles transient network/DB failures without retrying forever.
     * Each attempt is spaced by the backoff() method below.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param  string  $tenantId  The tenant's primary key
     * @param  array   $options   Optional backup options forwarded to BackupManager::backupTenant()
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array  $options = [],
    ) {
        $this->timeout = (int) config('vanguard.queue.timeout', 3600);
    }

    /**
     * Execute the job.
     *
     * The special sentinel value '__landlord__' triggers a landlord backup.
     * All other tenant IDs are resolved via TenancyResolver and delegate to
     * BackupManager::backupTenant().
     *
     * @param  BackupManager    $manager
     * @param  TenancyResolver  $tenancy
     */
    public function handle(BackupManager $manager, TenancyResolver $tenancy): void
    {
        if ($this->tenantId === '__landlord__') {
            $manager->backupLandlord($this->options);
            return;
        }

        $tenant = $tenancy->findTenant($this->tenantId);
        $manager->backupTenant($tenant, $this->options);
    }

    /**
     * Exponential backoff: 60 s, 300 s, 900 s between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Called by Laravel after all attempts are exhausted.
     * Logs a final error so operators can be alerted via log monitoring.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[Vanguard] Backup job failed permanently after all retries', [
            'tenant_id' => $this->tenantId,
            'error'     => $e->getMessage(),
            'attempts'  => $this->attempts(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the queued job.
     *
     * Used by Laravel Horizon and queue monitoring tools to group related jobs.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['vanguard', "tenant:{$this->tenantId}"];
    }
}
