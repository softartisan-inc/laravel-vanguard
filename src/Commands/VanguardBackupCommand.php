<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Jobs\RunTenantBackupJob;
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

    /**
     * Execute the console command.
     *
     * Exactly one of --landlord, --tenant, --all-tenants, or --filesystem must be provided.
     * Exits with FAILURE and an informative message when multi-tenancy is disabled
     * but a tenant target is requested.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(BackupManager $manager, TenancyResolver $tenancy): int
    {
        $this->info('<fg=cyan>🛡  Vanguard Backup Manager</>');
        $this->newLine();

        $options = [
            'include_filesystem' => ! $this->option('no-filesystem'),
        ];

        // --queue promises to dispatch even when the queue is disabled in
        // config. It used to be declared and read by nobody, so the flag ran
        // the backup inline like any other invocation.
        if ($this->option('queue')) {
            config(['vanguard.queue.enabled' => true]);
        }

        // ─── Landlord ─────────────────────────────────────────────
        if ($this->option('landlord')) {
            if ($this->dispatchToQueue('__landlord__', $options, 'landlord')) {
                return self::SUCCESS;
            }

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

            if ($this->dispatchToQueue((string) $tenantId, $options, "tenant [{$tenantId}]")) {
                return self::SUCCESS;
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
            if ($this->dispatchToQueue('__filesystem__', $options, 'filesystem')) {
                return self::SUCCESS;
            }

            $this->info('⏳ Running filesystem backup...');
            $record = $manager->backupFilesystem($options);
            $this->printResult($record);

            return self::SUCCESS;
        }

        $this->error('Please specify a backup target: --landlord, --tenant=ID, --all-tenants, or --filesystem');
        $this->line('Run <comment>php artisan vanguard:backup --help</comment> for usage.');

        return self::FAILURE;
    }

    /**
     * Dispatch this backup as a job when the queue is enabled.
     *
     * Only --all-tenants ever consulted the queue setting; a landlord, single
     * tenant or filesystem backup ran inline whatever the configuration said,
     * which is a long blocking command on a large installation.
     *
     * @param  string  $target  Tenant key, or the '__landlord__' / '__filesystem__' sentinel
     * @param  array  $options  Options forwarded to the backup
     * @param  string  $label  What to name in the console message
     * @return bool True when the work was queued and nothing should run inline
     */
    protected function dispatchToQueue(string $target, array $options, string $label): bool
    {
        // Only the explicit flag dispatches. Following queue.enabled here
        // would turn a hand-typed backup into a job that does nothing at all
        // when no worker is running — the silent no-op this package exists to
        // avoid. Scheduled tenant runs still queue through backupAllTenants().
        if (! $this->option('queue')) {
            return false;
        }

        RunTenantBackupJob::dispatch($target, $options)
            ->onConnection(config('vanguard.queue.connection'))
            ->onQueue(config('vanguard.queue.queue', 'vanguard'));

        $this->line("  ✓ Backup for {$label}: queued");

        return true;
    }

    /**
     * Print the outcome of a single backup operation to the console.
     *
     * @param  mixed  $record  A BackupRecord instance (typed as mixed to avoid import in commands)
     */
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
