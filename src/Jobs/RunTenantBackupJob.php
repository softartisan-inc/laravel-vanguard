<?php

namespace SoftArtisan\Vanguard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class RunTenantBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $tenantId  The tenant's primary key
     * @param  array   $options   Optional backup options forwarded to BackupManager::backupTenant()
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly array  $options = [],
    ) {}

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
