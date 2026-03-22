<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;

class VanguardCleanupTmpCommand extends Command
{
    protected $signature = 'vanguard:cleanup-tmp
                            {--hours=6 : Remove directories older than this many hours (default: 6)}';

    protected $description = 'Remove orphaned Vanguard tmp directories left by crashed workers';

    /**
     * Execute the console command.
     *
     * Scans the configured tmp base directory for session directories matching
     * the vanguard_* pattern and deletes any that are older than --hours.
     * These directories are normally cleaned up by BackupStorageManager::cleanTmp()
     * in a finally block, but crashed workers can leave them behind.
     *
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $base  = rtrim(config('vanguard.tmp_path', storage_path('vanguard-tmp')), '/');
        $hours = max(1, (int) $this->option('hours'));

        if (! is_dir($base)) {
            $this->info('No Vanguard tmp directory found — nothing to clean.');
            return self::SUCCESS;
        }

        $entries = array_diff(scandir($base), ['.', '..']);
        $cutoff  = time() - ($hours * 3600);
        $removed = 0;

        foreach ($entries as $entry) {
            if (! str_starts_with($entry, 'vanguard_')) {
                continue;
            }

            $path = $base.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($path)) {
                continue;
            }

            if (filemtime($path) < $cutoff) {
                exec(sprintf('rm -rf %s', escapeshellarg($path)));
                $removed++;
            }
        }

        $this->info("Removed {$removed} orphaned Vanguard tmp director".($removed === 1 ? 'y' : 'ies').".");
        return self::SUCCESS;
    }
}
