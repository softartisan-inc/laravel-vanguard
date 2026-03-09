<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class RestoreService
{
    public function __construct(
        protected DatabaseDriver       $db,
        protected StorageDriver        $storage,
        protected BackupStorageManager $store,
    ) {}

    /**
     * Restore a backup identified by a BackupRecord.
     *
     * @param  array  $options  ['verify_checksum' => bool, 'restore_db' => bool, 'restore_storage' => bool]
     */
    public function restore(BackupRecord $record, array $options = []): bool
    {
        $verify          = $options['verify_checksum'] ?? true;
        $restoreDb       = $options['restore_db']      ?? true;
        $restoreStorage  = $options['restore_storage'] ?? false; // opt-in: dangerous
        $useRemote       = $options['use_remote']      ?? false;

        if ($record->isFailed() || $record->isRunning()) {
            throw new RuntimeException("Cannot restore a backup with status [{$record->status}].");
        }

        $storedPath = $useRemote ? $record->remote_path : $record->file_path;

        if (! $storedPath) {
            throw new RuntimeException("No file path available for backup #{$record->id}.");
        }

        try {
            Log::info('[Vanguard] Starting restore', ['record_id' => $record->id]);

            $bundlePath = $this->store->download($storedPath, $useRemote);

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
