<?php

namespace SoftArtisan\Vanguard\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class RestoreService
{
    public function __construct(
        protected DatabaseDriver $db,
        protected StorageDriver $storage,
        protected BackupStorageManager $store,
    ) {}

    /**
     * Restore a backup identified by a BackupRecord.
     *
     * Downloads the bundle, verifies its checksum (if requested), extracts the
     * component files, and delegates to the appropriate restore method based on
     * the backup type (landlord / tenant / filesystem).
     *
     * @param  array  $options  Supported keys:
     *                          - 'verify_checksum' (bool)   — default true
     *                          - 'restore_db'      (bool)   — default true
     *                          - 'restore_storage' (bool)   — default false (opt-in, destructive)
     *                          - 'wipe_storage'    (bool)   — default false; replace instead of merge,
     *                          see wipeBackedUpPaths() for the exact scope erased
     *                          - 'source'          (string) — 'local' | 'remote' | 'ftp'; omit it to
     *                          read from the first destination the backup actually reached
     *                          - 'on_phase'        (callable) — receives (string $phase, array $context = []);
     *                          announces progress through downloading, verifying, unpacking and the
     *                          restore halves. Optional; a restore behaves exactly the same without it.
     * @return bool true on success
     *
     * @throws RuntimeException
     */
    public function restore(BackupRecord $record, array $options = []): bool
    {
        $verify = $options['verify_checksum'] ?? true;
        $restoreDb = $options['restore_db'] ?? true;
        $restoreStorage = $options['restore_storage'] ?? false; // opt-in: dangerous
        $wipeStorage = $options['wipe_storage'] ?? false; // opt-in: replace instead of merge
        $destination = $options['source'] ?? null; // 'local' | 'remote' | 'ftp', null to auto-detect
        $onPhase = $options['on_phase'] ?? null;

        if ($record->isFailed() || $record->isRunning()) {
            throw new RuntimeException("Cannot restore a backup with status [{$record->status}].");
        }

        [$destination, $storedPath] = $this->resolveSource($record, $destination);

        try {
            Log::info('[Vanguard] Starting restore', ['record_id' => $record->id]);

            $this->announce($onPhase, 'downloading', ['source' => $destination]);
            $bundlePath = $this->store->download($storedPath, $destination);

            // Integrity check
            if ($verify && $record->checksum) {
                $this->announce($onPhase, 'verifying');
                if (! $this->store->verifyChecksum($bundlePath, $record->checksum)) {
                    throw new RuntimeException(
                        "Checksum mismatch for backup #{$record->id}. The file may be corrupted."
                    );
                }
            }

            $this->announce($onPhase, 'unpacking');
            $components = $this->store->unBundle($bundlePath);

            if ($record->type === 'landlord') {
                return $this->restoreLandlord($record, $components, $restoreDb, $restoreStorage, $wipeStorage, $onPhase);
            }

            if ($record->type === 'tenant') {
                return $this->restoreTenant($record, $components, $restoreDb, $restoreStorage, $wipeStorage, $onPhase);
            }

            if ($record->type === 'filesystem') {
                return $this->restoreFilesystem($components, $wipeStorage, $onPhase);
            }

            throw new RuntimeException("Unknown backup type: [{$record->type}]");
        } finally {
            $this->store->cleanTmp();
        }
    }

    /**
     * Resolve which destination the bundle is read back from.
     *
     * Without an explicit choice, the first destination that actually holds a
     * path wins, local first since it needs no download. Defaulting blindly to
     * local made restores impossible on the recommended production setup,
     * where local is disabled and only the remote copy exists.
     *
     * An explicit choice is honoured as given, so a caller can still force the
     * remote copy even when a local one is present.
     *
     * @param  string|null  $requested  'local', 'remote', 'ftp', or null to auto-detect
     * @return array{0: string, 1: string} The resolved destination and its stored path
     *
     * @throws RuntimeException When the requested destination holds no path, or
     *                          when the backup reached no destination at all.
     */
    protected function resolveSource(BackupRecord $record, ?string $requested): array
    {
        $paths = array_filter([
            'local' => $record->file_path,
            'remote' => $record->remote_path,
            'ftp' => $record->ftp_path,
        ]);

        if ($requested !== null) {
            if (! isset($paths[$requested])) {
                throw new RuntimeException(sprintf(
                    'No file path available for backup #%d on destination [%s]. %s',
                    $record->id,
                    $requested,
                    $paths
                        ? 'Available: '.implode(', ', array_keys($paths)).'.'
                        : 'This backup reached no destination at all.'
                ));
            }

            return [$requested, $paths[$requested]];
        }

        if ($paths === []) {
            throw new RuntimeException(
                "Backup #{$record->id} has no stored file on any destination."
            );
        }

        $destination = array_key_first($paths);

        return [$destination, $paths[$destination]];
    }

    /**
     * Restore a landlord (central) backup.
     *
     * Restores the central database and/or filesystem depending on the flags passed.
     *
     * @param  array  $components  Extracted component paths keyed by 'database' and 'storage'
     * @param  bool  $db  Whether to restore the database
     * @param  bool  $fs  Whether to restore the filesystem
     * @param  bool  $wipe  Whether to erase the backed-up paths before extracting
     */
    protected function restoreLandlord(BackupRecord $record, array $components, bool $db, bool $fs, bool $wipe = false, ?callable $onPhase = null): bool
    {
        if ($db && isset($components['database'])) {
            $this->announce($onPhase, 'restoring database');

            $driver = config('database.default');
            $config = config("database.connections.{$driver}");

            $this->preservingCatalogue(function () use ($driver, $config, $components) {
                $this->db->restore($driver, $config, $components['database']);
            });

            Log::info('[Vanguard] Landlord DB restored', ['record_id' => $record->id]);
        }

        if ($fs && isset($components['storage'])) {
            $this->announce($onPhase, 'restoring files');
            $this->extractStorage($components['storage'], $wipe);
            Log::info('[Vanguard] Landlord filesystem restored', ['record_id' => $record->id]);
        }

        return true;
    }

    /**
     * Run a database restore, then put Vanguard's own bookkeeping back as it was.
     *
     * The landlord dump contains Vanguard's own tables themselves, captured
     * while the backup was still running. Restoring it therefore rewound the
     * bookkeeping: the backup being restored came back as 'running' — which the
     * guard clause then refuses to restore a second time — every backup taken
     * after the dump disappeared from the record, archives included, and the
     * restore's own history row (this very operation) vanished with it.
     *
     * Both vanguard_backups and vanguard_restores are affected the same way,
     * since both live in the landlord database the dump just overwrote. So both
     * are snapshotted here: read before the restore and written back after it,
     * so the catalogue keeps describing the archives that actually exist and the
     * history keeps describing the restores that actually ran.
     *
     * @param  callable  $restore  Performs the database restore
     */
    protected function preservingCatalogue(callable $restore): void
    {
        $models = [new BackupRecord, new RestoreRecord];

        $snapshots = [];
        foreach ($models as $model) {
            $table = $model->getTable();

            $snapshots[$table] = [
                'connection' => $model->getConnection(),
                'rows' => $model->getConnection()->table($table)->get()
                    ->map(fn ($row) => (array) $row)
                    ->all(),
            ];
        }

        $restore();

        foreach ($snapshots as $table => $snapshot) {
            if ($snapshot['rows'] === []) {
                continue;
            }

            try {
                $connection = $snapshot['connection'];
                $connection->table($table)->delete();

                foreach (array_chunk($snapshot['rows'], 200) as $chunk) {
                    $connection->table($table)->insert($chunk);
                }
            } catch (\Throwable $e) {
                // The data restore succeeded; only the bookkeeping did not. Say so
                // rather than failing a restore that actually worked.
                Log::error("[Vanguard] Could not put the {$table} table back after the restore", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Restore a tenant backup.
     *
     * Initialises the tenancy context for the target tenant, then restores
     * the tenant database and/or filesystem. Tenancy context is always ended
     * in a finally block.
     *
     * @param  array  $components  Extracted component paths keyed by 'database' and 'storage'
     * @param  bool  $db  Whether to restore the database
     * @param  bool  $fs  Whether to restore the filesystem
     * @param  bool  $wipe  Whether to erase the backed-up paths before extracting
     */
    protected function restoreTenant(BackupRecord $record, array $components, bool $db, bool $fs, bool $wipe = false, ?callable $onPhase = null): bool
    {
        $tenantModel = config('vanguard.tenancy.tenant_model', Tenant::class);
        $tenant = $tenantModel::findOrFail($record->tenant_id);

        tenancy()->initialize($tenant);

        try {
            if ($db && isset($components['database'])) {
                $this->announce($onPhase, 'restoring database');

                $resolver = app(TenancyResolver::class);
                $dbConf = $resolver->tenantDbConfig();
                $this->db->restore($dbConf['driver'], $dbConf, $components['database']);
                Log::info('[Vanguard] Tenant DB restored', ['tenant' => $record->tenant_id]);
            }

            if ($fs && isset($components['storage'])) {
                $this->announce($onPhase, 'restoring files');
                $this->extractStorage($components['storage'], $wipe);
                Log::info('[Vanguard] Tenant filesystem restored', ['tenant' => $record->tenant_id]);
            }
        } finally {
            tenancy()->end();
        }

        return true;
    }

    /**
     * Restore a filesystem-only backup.
     *
     * Extracts the storage component into storage_path(). Existing files are
     * merged unless the caller explicitly asked for the backed-up paths to be
     * erased first (wipe is opt-in to avoid accidental data loss).
     *
     * @param  array  $components  Extracted component paths keyed by 'storage'
     * @param  bool  $wipe  Whether to erase the backed-up paths before extracting
     */
    protected function restoreFilesystem(array $components, bool $wipe = false, ?callable $onPhase = null): bool
    {
        if (isset($components['storage'])) {
            $this->announce($onPhase, 'restoring files');
            $this->extractStorage($components['storage'], $wipe);
        }

        return true;
    }

    /**
     * Extract a storage component into storage_path().
     *
     * When $wipe is true the backed-up directories are emptied first, so the
     * result is the point-in-time state of the backup instead of a merge.
     * The extraction itself never wipes: StorageDriver::extract() would delete
     * the whole destination, and the destination here is storage_path().
     *
     * @param  string  $source  Absolute path to the storage archive
     * @param  bool  $wipe  Whether to erase the backed-up paths first
     */
    protected function extractStorage(string $source, bool $wipe): void
    {
        if ($wipe) {
            $this->wipeBackedUpPaths();
        }

        $this->storage->extract(
            source: $source,
            destination: storage_path(),
            wipe: false,
        );
    }

    /**
     * Empty every directory that the filesystem backup covers.
     *
     * Deliberately scoped to vanguard.sources.filesystem_paths: only what the
     * backup can put back may be erased. Logs, framework caches, sessions and
     * anything else living in storage_path() outside that list must survive a
     * restore, so storage_path() itself is never wiped.
     *
     * Each directory node is kept and only its content removed, so permissions
     * — and any symlink pointing at it — stay intact.
     */
    protected function wipeBackedUpPaths(): void
    {
        foreach ($this->backedUpPaths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                exec(sprintf('rm -rf %s', escapeshellarg($path.DIRECTORY_SEPARATOR.$entry)));
            }

            Log::info('[Vanguard] Storage path wiped before restore', ['path' => $path]);
        }
    }

    /**
     * The absolute directories a filesystem restore is allowed to erase.
     *
     * Resolved from vanguard.sources.filesystem_paths relative to storage_path(),
     * exactly the way StorageDriver resolves them when creating the backup.
     * Entries that do not sit strictly below storage_path() are dropped: a stray
     * '', '.' or '../..' must never turn a restore into a full storage wipe.
     *
     * @return array<string>
     */
    public function backedUpPaths(): array
    {
        $root = realpath(storage_path()) ?: rtrim(storage_path(), DIRECTORY_SEPARATOR);

        $safe = [];

        foreach ((array) config('vanguard.sources.filesystem_paths', ['app']) as $relative) {
            $path = rtrim(storage_path($relative), DIRECTORY_SEPARATOR);
            $resolved = realpath($path);

            // A path that does not exist yet is kept as configured: nothing to
            // erase, but callers still want to name it when asking to confirm.
            if ($resolved !== false) {
                $path = rtrim($resolved, DIRECTORY_SEPARATOR);
            }

            if (! str_starts_with($path.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) || $path === $root) {
                Log::warning('[Vanguard] Refusing to wipe a path outside storage_path()', [
                    'configured' => $relative,
                    'resolved' => $path,
                ]);

                continue;
            }

            $safe[] = $path;
        }

        return $safe;
    }

    /**
     * Announce the phase a restore has reached.
     *
     * Deliberately not a percentage: nothing in the chain — the download, tar,
     * the database client — reports meaningful progress, and an invented
     * percentage is a false statement. A phase is true.
     *
     * @param  callable|null  $onPhase  Receives (string $phase, array $context)
     */
    protected function announce(?callable $onPhase, string $phase, array $context = []): void
    {
        if ($onPhase !== null) {
            $onPhase($phase, $context);
        }
    }
}
