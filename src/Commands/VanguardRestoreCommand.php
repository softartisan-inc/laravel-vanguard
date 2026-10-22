<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Events\RestoreCompleted;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Events\RestoreStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\Encryption\AgeEncryptor;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;
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
                            {--identity= : age identity (private key) file that decrypts an encrypted backup}
                            {--identity-stdin : Read the age identity from a hidden prompt, or from stdin when piped}
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

        // In single-database mode a tenant backup is the tenant's files and
        // nothing else: the shared database comes back from a landlord backup.
        $filesOnly = $record->type === 'tenant'
            && ! TenancyResolver::databaseModeIsDefault()
            && app(TenancyResolver::class)->sharesOneDatabase();

        if ($filesOnly && $targetDatabase !== null) {
            $this->error('--database does not apply here: in single-database mode a tenant backup is files only, with no database to redirect.');

            return self::FAILURE;
        }

        if ($filesOnly && ! $this->option('restore-storage')) {
            $this->error('In single-database mode a tenant backup is files only: add --restore-storage to restore them.');
            $this->line('The shared database is restored from a landlord backup.');

            return self::FAILURE;
        }

        // Encrypted archives (opt-in): the key is checked before anything is
        // asked or written, so a restore that cannot decrypt never opens a
        // history row nor prompts for a confirmation.
        $identityFile = $this->option('identity');
        $identityStdin = (bool) $this->option('identity-stdin');
        $encrypted = AgeEncryptor::isEncryptedRecord($record);

        if ($identityFile !== null && $identityStdin) {
            $this->error('Pass either --identity=<file> or --identity-stdin, not both.');

            return self::FAILURE;
        }

        if ($encrypted && ($identityFile === null || $identityFile === '') && ! $identityStdin) {
            $this->error("Backup #{$record->id} is age-encrypted: pass --identity=<file> (or --identity-stdin) with the private key that matches one of its recipients.");

            return self::FAILURE;
        }

        if ($encrypted && $identityFile !== null && (! is_file($identityFile) || ! is_readable($identityFile))) {
            $this->error('The --identity file cannot be read.');

            return self::FAILURE;
        }

        $reached = $record->reachedDestinations();
        $source = $this->option('source') ?: array_key_first($reached);

        $this->warn('⚠️  You are about to restore a backup. This will overwrite existing data.');
        $rows = [
            ['ID',       $record->id],
            ['Type',     $record->type],
            ['Tenant',   $record->tenant_id ?? 'landlord'],
            ['Created',  $record->created_at->toDateTimeString()],
            ['Size',     $record->file_size_human],
            ['Status',   $record->status],
            ['Stored on', $reached !== [] ? implode(', ', array_keys($reached)) : '<fg=red>nowhere</>'],
            ['Reads from', $source ?? '<fg=red>nothing — this archive reached no destination</>'],
            ['Writes to', $filesOnly
                ? 'files only (single-database mode: a tenant backup holds no database)'
                : ($targetDatabase ?? "the target's own database")],
        ];

        if ($encrypted) {
            $rows[] = ['Encrypted', 'age — decrypted on this machine with the identity given'];
        }

        $this->table(['Field', 'Value'], $rows);

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

        // Read after the confirmations, so nothing secret sits in memory
        // while the operator decides. Written to a 0600 file only age reads,
        // erased in the finally block below whatever the restore did.
        $identityCopy = null;

        if ($encrypted && $identityStdin) {
            try {
                $identityCopy = $this->writeIdentityCopy($this->readIdentity());
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $identityFile = $identityCopy;
        }

        try {
            return $this->runRestore($restoreService, $record, $targetDatabase, $wipeStorage, $encrypted ? $identityFile : null);
        } finally {
            if ($identityCopy !== null) {
                $this->eraseIdentityCopy($identityCopy);
            }
        }
    }

    /**
     * Run the restore and keep its history row current.
     *
     * @param  string|null  $identityFile  The age identity for an encrypted archive, null otherwise
     */
    protected function runRestore(RestoreService $restoreService, BackupRecord $record, ?string $targetDatabase, bool $wipeStorage, ?string $identityFile): int
    {
        // Opened before the first byte moves, so a restore killed halfway
        // still leaves a row an operator can find. Written on the same
        // connection as the endpoint's, so both paths land in one history.
        $restore = $this->openHistoryRow($record, $targetDatabase);

        $restore->markRunning();
        event(new RestoreStarted($restore));

        $options = [
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
        ];

        // Only for an encrypted archive: a plaintext restore is handed
        // exactly the options it always was. The path goes to the service
        // and to age, never to the history row.
        if ($identityFile !== null) {
            $options['identity_file'] = $identityFile;
        }

        try {
            $restoreService->restore($record, $options);

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
     * The age identity typed at a hidden prompt, or piped on stdin.
     *
     * Never echoed, never logged. An identity protected by a passphrase is
     * not supported this way: age asks for the passphrase on a terminal, so
     * pass such a file with --identity from an interactive session instead.
     *
     * @throws \RuntimeException When nothing was provided
     */
    protected function readIdentity(): string
    {
        $identity = $this->input->isInteractive()
            ? (string) $this->secret('age identity (the private key, input hidden)')
            : (string) stream_get_contents(STDIN);

        if (trim($identity) === '') {
            throw new \RuntimeException('No age identity was provided on --identity-stdin.');
        }

        return rtrim($identity)."\n";
    }

    /**
     * Write the identity to a 0600 file in a fresh 0700 directory under the
     * Vanguard tmp path — named like a session directory, so the orphan sweep
     * (vanguard:cleanup-tmp) removes it should this process be killed before
     * its finally block runs.
     *
     * @return string Path of the file
     */
    protected function writeIdentityCopy(string $identity): string
    {
        $base = rtrim((string) config('vanguard.tmp_path', storage_path('vanguard-tmp')), '/');
        $dir = $base.'/vanguard_'.bin2hex(random_bytes(16));

        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create a private directory for the age identity under vanguard.tmp_path.');
        }

        $file = $dir.'/identity';
        $previous = umask(0077);

        try {
            if (file_put_contents($file, $identity) === false) {
                throw new \RuntimeException('Cannot write the age identity to the private tmp directory.');
            }
        } finally {
            umask($previous);
        }

        chmod($file, 0600);

        return $file;
    }

    /**
     * Overwrite, then remove, the identity copy and its directory.
     */
    protected function eraseIdentityCopy(string $file): void
    {
        if (is_file($file)) {
            $size = (int) filesize($file);
            @file_put_contents($file, str_repeat("\0", max($size, 1)));
            @unlink($file);
        }

        @rmdir(dirname($file));
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
