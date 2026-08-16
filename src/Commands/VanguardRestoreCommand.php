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
                            {--database= : Write to this database instead of the target\'s own, for this run only (rehearsal)}
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
     * --database redirects the restore into a throwaway database for this run
     * only, so an operator can rehearse one without repointing the application.
     * That is the difference between believing the backups work and knowing it.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(RestoreService $restoreService): int
    {
        $wipeStorage = (bool) $this->option('wipe-storage');
        $targetDatabase = $this->option('database');

        // An allowlist, not escaping — the same rule the scheduler applies to a
        // tenant key, and for the same reason: this value is interpolated into
        // the command line handed to mysql/psql, and a database name carrying a
        // space, a quote or a semicolon has no business being there. Quoting it
        // would only hide how odd it is.
        if ($targetDatabase !== null && preg_match('/^[A-Za-z0-9_.\-]+$/', $targetDatabase) !== 1) {
            $this->error('--database must be a plain database identifier: letters, digits, underscore, dot or hyphen only.');

            return self::FAILURE;
        }

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
            ['Writes to', $targetDatabase ?? "the target's own database"],
        ]);

        // Said again, in its own line, before the prompt: a rehearsal that
        // silently hits production is worse than no rehearsal at all, and a row
        // in a table is exactly the kind of thing an operator skims past.
        if ($targetDatabase !== null) {
            $this->warn("⚠️  TARGET REDIRECTED — this restore writes to the database [{$targetDatabase}], not to the target's own.");
        }

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
                'restore_db' => ! $this->option('no-db'),
                'restore_storage' => $this->option('restore-storage'),
                'wipe_storage' => $wipeStorage,
                'source' => $this->option('source') ?: null,
                'database' => $targetDatabase,
            ]);

            $this->info($targetDatabase !== null
                ? "✅ Restore completed successfully into [{$targetDatabase}]."
                : '✅ Restore completed successfully.');

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
     * @return bool Whether the operator confirmed
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
