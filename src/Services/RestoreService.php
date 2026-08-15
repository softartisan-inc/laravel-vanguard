<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class RestoreService
{
    /**
     * @param  DatabaseDriver       $db
     * @param  StorageDriver        $storage
     * @param  BackupStorageManager $store
     */
    public function __construct(
        protected DatabaseDriver       $db,
        protected StorageDriver        $storage,
        protected BackupStorageManager $store,
    ) {}

    /**
     * Restore a backup identified by a BackupRecord.
     *
     * Downloads the bundle, verifies its checksum (if requested), extracts the
     * component files, and delegates to the appropriate restore method based on
     * the backup type (landlord / tenant / filesystem).
     *
     * @param  BackupRecord  $record
     * @param  array  $options  Supported keys:
     *                          - 'verify_checksum' (bool)   — default true
     *                          - 'restore_db'      (bool)   — default true
     *                          - 'restore_storage' (bool)   — default false (opt-in, destructive)
     *                          - 'source'          (string) — 'local' | 'remote' | 'ftp'; omit it to
     *                            read from the first destination the backup actually reached
     * @return bool  true on success
     *
     * @throws RuntimeException
     */
    public function restore(BackupRecord $record, array $options = []): bool
    {
        $verify         = $options['verify_checksum'] ?? true;
        $restoreDb      = $options['restore_db']      ?? true;
        $restoreStorage = $options['restore_storage'] ?? false; // opt-in: dangerous
        $destination    = $options['source']          ?? null; // 'local' | 'remote' | 'ftp', null to auto-detect

        if ($record->isFailed() || $record->isRunning()) {
            throw new RuntimeException("Cannot restore a backup with status [{$record->status}].");
        }

        [$destination, $storedPath] = $this->resolveSource($record, $destination);

        try {
            Log::info('[Vanguard] Starting restore', ['record_id' => $record->id]);

            $bundlePath = $this->store->download($storedPath, $destination);

            // Integrity check
            if ($verify && $record->checksum) {
                if (! $this->store->verifyChecksum($bundlePath, $record->checksum)) {
                    throw new RuntimeException(
                        "Checksum mismatch for backup #{$record->id}. The file may be corrupted."
                    );
                }
            }

            $components = $this->store->unBundle($bundlePath);

            if ($record->type === 'landlord') {
                return $this->restoreLandlord($record, $components, $restoreDb, $restoreStorage);
            }

            if ($record->type === 'tenant') {
                return $this->restoreTenant($record, $components, $restoreDb, $restoreStorage);
            }

            if ($record->type === 'filesystem') {
                return $this->restoreFilesystem($components);
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
     * @param  BackupRecord  $record
     * @param  string|null   $requested  'local', 'remote', 'ftp', or null to auto-detect
     * @return array{0: string, 1: string}  The resolved destination and its stored path
     *
     * @throws RuntimeException When the requested destination holds no path, or
     *                          when the backup reached no destination at all.
     */
    protected function resolveSource(BackupRecord $record, ?string $requested): array
    {
        $paths = array_filter([
            'local'  => $record->file_path,
            'remote' => $record->remote_path,
            'ftp'    => $record->ftp_path,
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
     * @param  BackupRecord  $record
     * @param  array         $components  Extracted component paths keyed by 'database' and 'storage'
     * @param  bool          $db          Whether to restore the database
     * @param  bool          $fs          Whether to restore the filesystem
     * @return bool
     */
    protected function restoreLandlord(BackupRecord $record, array $components, bool $db, bool $fs): bool
    {
        if ($db && isset($components['database'])) {
            $driver = config('database.default');
            $config = config("database.connections.{$driver}");
            $this->db->restore($driver, $config, $components['database']);
            Log::info('[Vanguard] Landlord DB restored', ['record_id' => $record->id]);
        }

        if ($fs && isset($components['storage'])) {
            $this->storage->extract(
                source: $components['storage'],
                destination: storage_path(),
                wipe: false,
            );
            Log::info('[Vanguard] Landlord filesystem restored', ['record_id' => $record->id]);
        }

        return true;
    }

    /**
     * Restore a tenant backup.
     *
     * Initialises the tenancy context for the target tenant, then restores
     * the tenant database and/or filesystem. Tenancy context is always ended
     * in a finally block.
     *
     * @param  BackupRecord  $record
     * @param  array         $components  Extracted component paths keyed by 'database' and 'storage'
     * @param  bool          $db          Whether to restore the database
     * @param  bool          $fs          Whether to restore the filesystem
     * @return bool
     */
    protected function restoreTenant(BackupRecord $record, array $components, bool $db, bool $fs): bool
    {
        $tenantModel = config('vanguard.tenancy.tenant_model', \App\Models\Tenant::class);
        $tenant      = $tenantModel::findOrFail($record->tenant_id);

        tenancy()->initialize($tenant);

        try {
            if ($db && isset($components['database'])) {
                $resolver = app(TenancyResolver::class);
                $dbConf   = $resolver->tenantDbConfig();
                $this->db->restore($dbConf['driver'], $dbConf, $components['database']);
                Log::info('[Vanguard] Tenant DB restored', ['tenant' => $record->tenant_id]);
            }

            if ($fs && isset($components['storage'])) {
                $this->storage->extract(
                    source: $components['storage'],
                    destination: storage_path(),
                    wipe: false,
                );
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
     * Extracts the storage component into storage_path() without wiping
     * existing files (wipe is opt-in to avoid accidental data loss).
     *
     * @param  array  $components  Extracted component paths keyed by 'storage'
     * @return bool
     */
    protected function restoreFilesystem(array $components): bool
    {
        if (isset($components['storage'])) {
            $this->storage->extract(
                source: $components['storage'],
                destination: storage_path(),
                wipe: false,
            );
        }
        return true;
    }
}
