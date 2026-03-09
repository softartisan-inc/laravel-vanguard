<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class VanguardBackupCommand extends Command
{
    protected $signature = 'vanguard:backup
                            {--landlord : Backup the central (landlord) database and filesystem}
                            {--tenant=  : Backup a specific tenant by ID}
                            {--all-tenants : Backup all tenants}
                            {--filesystem : Backup filesystem only (no DB)}
                            {--no-filesystem : Skip filesystem backup}
                            {--queue : Force dispatch to queue even if queue.enabled=false}';

    protected $description = 'Run a Vanguard backup (landlord, specific tenant, or all tenants)';

    public function handle(BackupManager $manager, TenancyResolver $tenancy): int
    {
        $this->info('<fg=cyan>🛡  Vanguard Backup Manager</>');
        $this->newLine();

        $options = [
            'include_filesystem' => ! $this->option('no-filesystem'),
        ];

        // ─── Landlord ─────────────────────────────────────────────
        if ($this->option('landlord')) {
            $this->info('⏳ Running landlord backup...');
            $record = $manager->backupLandlord($options);
            $this->printResult($record);
            return self::SUCCESS;
        }

        // ─── Specific Tenant ──────────────────────────────────────
        if ($tenantId = $this->option('tenant')) {
            if (! $tenancy->isEnabled()) {
                $this->error('Multi-tenancy is disabled. Check vanguard.tenancy.enabled in config.');
                return self::FAILURE;
            }

            $this->info("⏳ Running backup for tenant [{$tenantId}]...");
            $tenant = $tenancy->findTenant($tenantId);
            $record = $manager->backupTenant($tenant, $options);
            $this->printResult($record);
            return self::SUCCESS;
        }

        // ─── All Tenants ──────────────────────────────────────────
        if ($this->option('all-tenants')) {
            if (! $tenancy->isEnabled()) {
                $this->error('Multi-tenancy is disabled. Check vanguard.tenancy.enabled in config.');
                return self::FAILURE;
            }

            $count = $tenancy->allTenants()->count();
            $this->info("⏳ Running backup for all {$count} tenants...");

            $results = $manager->backupAllTenants($options);

            foreach ($results as $result) {
                if (isset($result['error'])) {
                    $this->error("  ✗ Tenant {$result['tenant']}: {$result['error']}");
                } elseif ($result['queued'] ?? false) {
                    $this->line("  ✓ Tenant {$result['tenant']}: queued");
                } else {
                    $this->line("  ✓ Tenant {$result['tenant']}: ".$result['record']->status);
                }
            }

            return self::SUCCESS;
        }

        // ─── Filesystem only ──────────────────────────────────────
        if ($this->option('filesystem')) {
            $this->info('⏳ Running filesystem backup...');
            $record = $manager->backupFilesystem($options);
            $this->printResult($record);
            return self::SUCCESS;
        }

        $this->error('Please specify a backup target: --landlord, --tenant=ID, --all-tenants, or --filesystem');
        $this->line('Run <comment>php artisan vanguard:backup --help</comment> for usage.');
        return self::FAILURE;
    }

    protected function printResult(mixed $record): void
    {
        if ($record->isCompleted()) {
            $this->info("  ✅ Completed in {$record->duration}");
            $this->line("     Size     : {$record->file_size_human}");
            $this->line("     Path     : {$record->file_path}");
            $this->line("     Checksum : {$record->checksum}");
        } else {
            $this->error("  ✗ Backup failed: {$record->error}");
        }
    }
}
