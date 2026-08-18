<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\StaleRunReaper;

class VanguardCleanupTmpCommand extends Command
{
    protected $signature = 'vanguard:cleanup-tmp
                            {--hours=6 : Remove directories older than this many hours (default: 6)}';

    protected $description = 'Sweep up after crashed Vanguard workers: orphaned tmp directories and rows left running';

    /**
     * Execute the console command.
     *
     * The tmp sweep itself lives in BackupStorageManager::cleanOrphanedTmp()
     * and the row sweep in StaleRunReaper, so the dashboard's
     * POST /api/cleanup-tmp runs exactly this and nothing beside it.
     *
     * @return int Command::SUCCESS
     */
    public function handle(BackupStorageManager $store, StaleRunReaper $reaper): int
    {
        $removed = $store->cleanOrphanedTmp((int) $this->option('hours'));

        $this->info("Removed {$removed} orphaned Vanguard tmp director".($removed === 1 ? 'y' : 'ies').'.');

        // The other half of the same crash: the tmp directory the killed worker
        // left behind, and the row it left saying `running`. Sweeping one and
        // not the other leaves the dashboard reassuring about a backup that
        // does not exist.
        $reclaimed = $reaper->reap();
        $total = $reclaimed['backups'] + $reclaimed['restores'];

        $this->info(sprintf(
            'Reclaimed %d stale run%s (%d backup%s, %d restore%s).',
            $total,
            $total === 1 ? '' : 's',
            $reclaimed['backups'],
            $reclaimed['backups'] === 1 ? '' : 's',
            $reclaimed['restores'],
            $reclaimed['restores'] === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
