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

    public function __construct(
        public readonly string $tenantId,
        public readonly array  $options = [],
    ) {}

    public function handle(BackupManager $manager, TenancyResolver $tenancy): void
    {
        $tenant = $tenancy->findTenant($this->tenantId);
        $manager->backupTenant($tenant, $this->options);
    }

    public function tags(): array
    {
        return ['vanguard', "tenant:{$this->tenantId}"];
    }
}
