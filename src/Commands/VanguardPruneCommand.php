<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\BackupStorageManager;

class VanguardPruneCommand extends Command
{
    protected $signature = 'vanguard:prune
                            {--tenant= : Prune only for a specific tenant}
                            {--days= : Override retention days}';

    protected $description = 'Prune old Vanguard backup records beyond the retention policy';

    /**
     * Execute the console command.
     *
     * Overrides the configured retention period when --days is passed.
     * Delegates the actual deletion to BackupStorageManager::pruneOldBackups().
     *
     * @param  BackupStorageManager  $store
     * @return int  Command::SUCCESS
     */
    public function handle(BackupStorageManager $store): int
    {
        if ($days = $this->option('days')) {
            config(['vanguard.retention.days' => (int) $days]);
        }

        $deleted = $store->pruneOldBackups($this->option('tenant'));

        $this->info("✅ Pruned {$deleted} old backup(s).");
        return self::SUCCESS;
    }
}
