<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Vanguard;

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

        $reached = $record->reachedDestinations();
        $source = $this->option('source') ?: array_key_first($reached);

        $this->warn('⚠️  You are about to restore a backup. This will overwrite existing data.');
        $this->table(['Field', 'Value'], [
            ['ID',       $record->id],
            ['Type',     $record->type],
            ['Tenant',   $record->tenant_id ?? 'landlord'],
            ['Created',  $record->created_at->toDateTimeString()],
            ['Size',     $record->file_size_human],
            ['Status',   $record->status],
            ['Stored on', $reached !== [] ? implode(', ', array_keys($reached)) : '<fg=red>nowhere</>'],
            ['Reads from', $source ?? '<fg=red>nothing — this archive reached no destination</>'],
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

        // Opened before the first byte moves, so a restore killed halfway
        // still leaves a row an operator can find. Written on the same
        // connection as the endpoint's, so both paths land in one history.
        $restore = $this->openHistoryRow($record, $targetDatabase);

        $restore->markRunning();
        event(new RestoreStarted($restore));

        try {
            $restoreService->restore($record, [
                'verify_checksum' => ! $this->option('no-verify'),
                'restore_db' => ! $this->option('no-db'),
                'restore_storage' => $this->option('restore-storage'),
                'wipe_storage' => $wipeStorage,
                'source' => $this->option('source') ?: null,
                'database' => $targetDatabase,
                'on_phase' => function (string $phase, array $context = []) use ($restore) {
                    $restore->markPhase($phase);
                    $this->printPhase($phase, $context);
                },
            ]);

            $restore->markCompleted();
            event(new RestoreCompleted($restore));

            $this->info($targetDatabase !== null
                ? "✅ Restore completed successfully into [{$targetDatabase}]."
                : '✅ Restore completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // The row first, then the event: an alert about a restore whose
            // row still says 'running' sends the operator to a screen that
            // contradicts it.
            $restore->markFailed($e->getMessage());
            event(new RestoreFailed($restore, $e));

            $this->error('✗ Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Open the history row for this run.
     *
     * The target fields are copied off the backup rather than left to the
     * relation, exactly as the endpoint does: the history has to survive the
     * deletion of the archive it restored.
     */
    protected function openHistoryRow(BackupRecord $record, ?string $targetDatabase): RestoreRecord
    {
        return RestoreRecord::on(Vanguard::centralConnection())->create([
            'backup_id' => $record->id,
            'type' => $record->type,
            'tenant_id' => $record->tenant_id,
            'backup_created_at' => $record->created_at,
            'source' => $this->option('source') ?: null,
            'target_database' => $targetDatabase,
            'restore_db' => ! $this->option('no-db'),
            'restore_storage' => (bool) $this->option('restore-storage'),
            'verify_checksum' => ! $this->option('no-verify'),
            'status' => 'pending',
            'requested_by' => $this->operator(),
            'origin' => 'console',
        ]);
    }

    /**
     * Name whoever is running this command.
     *
     * There is no authenticated user on a console, so the application's own
     * resolver gets asked first and the shell account plus the machine are the
     * fallback. That is not an identity, but it is what an audit of "who
     * restored production" has to start from, and it is strictly more than the
     * null this column used to hold.
     *
     * Nothing is prefixed here: that this run came from a console is recorded
     * in `origin`, and gluing it in front of the name would put two facts in
     * the column that answers "who".
     */
    protected function operator(): string
    {
        if ($actor = Vanguard::actor()) {
            return $actor;
        }

        $user = get_current_user() ?: (string) (getenv('USER') ?: 'unknown');

        return $user.'@'.(gethostname() ?: 'unknown');
    }

    /**
     * Print the phase a restore has reached.
     *
     * A restore of a large target runs for minutes with nothing on screen; and
     * the storage phase is where the console learns that the filesystem member
     * of the archive holds no file — a restore that puts nothing back must not
     * end on "Restore completed successfully" alone.
     *
     * @param  array  $context  Phase context; 'empty' is set on the storage phase
     */
    protected function printPhase(string $phase, array $context = []): void
    {
        $this->line("  → {$phase}");

        if ($phase === 'restoring files' && ($context['empty'] ?? false)) {
            $this->warn('  ⚠️  The filesystem member of this backup holds no file: nothing will be restored from it.');

            if ($this->option('wipe-storage')) {
                $this->warn('     --wipe-storage was refused: erasing your files to replace them with an empty archive is not a restore.');
            }
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
