<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\RestoreService;

class VanguardRestoreCommand extends Command
{
    protected $signature = 'vanguard:restore
                            {id : The backup record ID to restore}
                            {--source= : Read the bundle from local, remote or ftp (default: the first destination the backup reached)}
                            {--no-verify : Skip checksum verification}
                            {--no-db : Skip database restore}
                            {--restore-storage : Also restore filesystem (dangerous)}
                            {--wipe-storage : Erase the backed-up directories before extracting instead of merging (requires --restore-storage)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Restore a backup by its record ID';

    /**
     * Execute the console command.
     *
     * Displays backup metadata, prompts for confirmation (unless --force) —
     * twice when --wipe-storage is used, since that one erases data the backup
     * may not put back — then delegates to RestoreService::restore().
     *
     * @param  RestoreService  $restoreService
     * @return int  Command::SUCCESS or Command::FAILURE
     */
    public function handle(RestoreService $restoreService): int
    {
        $wipeStorage = (bool) $this->option('wipe-storage');

        // --wipe-storage is not made to imply --restore-storage: erasing files
        // is too destructive to be turned on by a flag the operator did not type.
        if ($wipeStorage && ! $this->option('restore-storage')) {
            $this->error('--wipe-storage only applies to a filesystem restore: add --restore-storage, or drop it.');
            return self::FAILURE;
        }

        $record = BackupRecord::find($this->argument('id'));

        if (! $record) {
            $this->error("Backup record [{$this->argument('id')}] not found.");
            return self::FAILURE;
        }

        $this->warn('⚠️  You are about to restore a backup. This will overwrite existing data.');
        $this->table(['Field', 'Value'], [
            ['ID',       $record->id],
            ['Type',     $record->type],
            ['Tenant',   $record->tenant_id ?? 'landlord'],
            ['Created',  $record->created_at->toDateTimeString()],
            ['Size',     $record->file_size_human],
            ['Status',   $record->status],
        ]);

        if (! $this->option('force') && ! $this->confirm('Do you want to proceed?')) {
            $this->info('Restore cancelled.');
            return self::SUCCESS;
        }

        if ($wipeStorage && ! $this->option('force') && ! $this->confirmWipe($restoreService)) {
            $this->info('Restore cancelled.');
            return self::SUCCESS;
        }

        try {
            $restoreService->restore($record, [
                'verify_checksum' => ! $this->option('no-verify'),
                'restore_db'      => ! $this->option('no-db'),
                'restore_storage' => $this->option('restore-storage'),
                'wipe_storage'    => $wipeStorage,
                'source'          => $this->option('source') ?: null,
            ]);

            $this->info('✅ Restore completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('✗ Restore failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Ask a second, separate confirmation naming the directories to be erased.
     *
     * The generic "Do you want to proceed?" says nothing about what disappears,
     * so --wipe-storage gets its own prompt listing the exact paths.
     *
     * @param  RestoreService  $restoreService
     * @return bool  Whether the operator confirmed
     */
    protected function confirmWipe(RestoreService $restoreService): bool
    {
        $paths = $restoreService->backedUpPaths();

        if ($paths === []) {
            $this->warn('⚠️  --wipe-storage has nothing to erase: no valid path in vanguard.sources.filesystem_paths.');
            return true;
        }

        $this->warn('⚠️  --wipe-storage will ERASE the content of these directories before extracting the backup:');

        foreach ($paths as $path) {
            $this->line('   • '.$path);
        }

        $this->warn('Anything created there since the backup will be lost. Other paths in storage are left alone.');

        return $this->confirm('Erase those directories and replace them with the backup content?');
    }
}
