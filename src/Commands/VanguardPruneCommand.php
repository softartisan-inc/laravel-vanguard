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
     * @return int Command::SUCCESS
     */
    public function handle(BackupStorageManager $store): int
    {
        $days = $this->option('days');

        if ($days !== null) {
            // Checked rather than cast: "0" is falsy, so --days=0 — prune
            // everything — used to be dropped silently and the configured
            // retention applied instead; and (int) 'abc' is 0, so a typo used
            // to prune every backup there is.
            if (! ctype_digit((string) $days)) {
                $this->error('--days must be a whole number of days, zero or more.');

                return self::FAILURE;
            }

            config(['vanguard.retention.days' => (int) $days]);
        }

        $deleted = $store->pruneOldBackups($this->option('tenant'));

        $this->info("✅ Pruned {$deleted} old backup(s).");

        return self::SUCCESS;
    }
}
