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
     *                          - 'source'          (string) — 'local' | 'remote' | 'ftp', default 'local'
     * @return bool  true on success
     *
     * @throws RuntimeException
     */
    public function restore(BackupRecord $record, array $options = []): bool
    {
        $verify         = $options['verify_checksum'] ?? true;
        $restoreDb      = $options['restore_db']      ?? true;
        $restoreStorage = $options['restore_storage'] ?? false; // opt-in: dangerous
        $destination    = $options['source']          ?? 'local'; // 'local' | 'remote' | 'ftp'

        if ($record->isFailed() || $record->isRunning()) {
            throw new RuntimeException("Cannot restore a backup with status [{$record->status}].");
        }

        $storedPath = match ($destination) {
            'remote' => $record->remote_path,
            'ftp'    => $record->ftp_path,
            default  => $record->file_path,
        };

        if (! $storedPath) {
            throw new RuntimeException("No file path available for backup #{$record->id} on destination [{$destination}].");
        }

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
