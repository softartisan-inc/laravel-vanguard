<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\BackupStorageManager;

class VanguardCleanupTmpCommand extends Command
{
    protected $signature = 'vanguard:cleanup-tmp
                            {--hours=6 : Remove directories older than this many hours (default: 6)}';

    protected $description = 'Remove orphaned Vanguard tmp directories left by crashed workers';

    /**
     * Execute the console command.
     *
     * The sweep itself lives in BackupStorageManager::cleanOrphanedTmp(), so
     * the dashboard's POST /api/cleanup-tmp runs exactly this and nothing
     * beside it.
     *
     * @return int Command::SUCCESS
     */
    public function handle(BackupStorageManager $store): int
    {
        $removed = $store->cleanOrphanedTmp((int) $this->option('hours'));

        $this->info("Removed {$removed} orphaned Vanguard tmp director".($removed === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
