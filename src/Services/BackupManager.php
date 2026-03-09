<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\BackupStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class BackupManager
{
    public function __construct(
        protected DatabaseDriver      $db,
        protected StorageDriver       $storage,
        protected BackupStorageManager $store,
        protected TenancyResolver     $tenancy,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────

    /**
     * Run a full landlord backup (central DB + filesystem).
     */
    public function backupLandlord(array $options = []): BackupRecord
    {
        $record = $this->createRecord(null, 'landlord', $options);

        try {
            event(new BackupStarted($record));

            $files = [];
            $name  = "landlord_{$record->id}_".now()->format('Ymd_His');

            // Central database
            if (config('vanguard.sources.landlord_database', true)) {
                $dbConf = $this->tenancy->landlordDbConfig();
                $files['database'] = $this->db->dump(
                    driver: $dbConf['driver'],
                    config: $dbConf,
                    destination: $this->store->tmpPath("{$name}_db.sql.gz"),
                );
            }

            // Filesystem
            if (config('vanguard.sources.filesystem', true) && ($options['include_filesystem'] ?? true)) {
                $files['storage'] = $this->storage->archive(
                    paths: $this->storage->resolveBackupPaths(),
                    exclude: $this->storage->resolveExcludePaths(),
                    destination: $this->store->tmpPath("{$name}_fs.tar.gz"),
                );
            }

            $bundle = $this->store->bundle($files, $name);
            $this->completeRecord($record, $bundle);

            event(new BackupCompleted($record));
            Log::info('[Vanguard] Landlord backup completed', ['id' => $record->id]);
        } catch (\Throwable $e) {
            $this->failRecord($record, $e);
            event(new BackupFailed($record, $e));
            throw $e;
        } finally {
            $this->store->cleanTmp();
        }

        return $record->fresh();
    }

    /**
     * Run a backup for a single tenant.
     */
    public function backupTenant(mixed $tenant, array $options = []): BackupRecord
    {
        $tenantId = $tenant->getTenantKey();
        $record   = $this->createRecord($tenantId, 'tenant', $options);

        try {
            event(new BackupStarted($record));

            $this->tenancy->runForTenant($tenant, function () use ($tenant, $record, $options, &$files, &$name) {
                $files = [];
                $name  = "tenant_{$tenant->getTenantKey()}_{$record->id}_".now()->format('Ymd_His');

                // Tenant database
                if (config('vanguard.sources.tenant_databases', true)) {
                    $dbConf = $this->tenancy->tenantDbConfig();
                    $files['database'] = $this->db->dump(
                        driver: $dbConf['driver'],
                        config: $dbConf,
                        destination: $this->store->tmpPath("{$name}_db.sql.gz"),
                    );
                }

                // Tenant storage (if tenant has its own storage disk)
                if (config('vanguard.sources.filesystem', true) && ($options['include_filesystem'] ?? false)) {
                    $files['storage'] = $this->storage->archive(
                        paths: $this->storage->resolveBackupPaths(),
                        exclude: $this->storage->resolveExcludePaths(),
                        destination: $this->store->tmpPath("{$name}_fs.tar.gz"),
                    );
                }
            });

            $bundle = $this->store->bundle($files, $name);
            $this->completeRecord($record, $bundle);

            event(new BackupCompleted($record));
            Log::info('[Vanguard] Tenant backup completed', ['tenant' => $tenantId, 'id' => $record->id]);
        } catch (\Throwable $e) {
            $this->failRecord($record, $e);
            event(new BackupFailed($record, $e));
            throw $e;
        } finally {
            $this->store->cleanTmp();
        }

        return $record->fresh();
    }

    /**
     * Run a filesystem-only backup (no DB).
     */
    public function backupFilesystem(array $options = []): BackupRecord
    {
        $record = $this->createRecord(null, 'filesystem', $options);

        try {
            event(new BackupStarted($record));

            $name = "filesystem_{$record->id}_".now()->format('Ymd_His');

            $files['storage'] = $this->storage->archive(
                paths: $this->storage->resolveBackupPaths(),
                exclude: $this->storage->resolveExcludePaths(),
                destination: $this->store->tmpPath("{$name}_fs.tar.gz"),
            );

            $bundle = $this->store->bundle($files, $name);
            $this->completeRecord($record, $bundle);

            event(new BackupCompleted($record));
            Log::info('[Vanguard] Filesystem backup completed', ['id' => $record->id]);
        } catch (\Throwable $e) {
            $this->failRecord($record, $e);
            event(new BackupFailed($record, $e));
            throw $e;
        } finally {
            $this->store->cleanTmp();
        }

        return $record->fresh();
    }

    /**
     * Backup ALL tenants sequentially (queue-friendly: dispatches jobs when queue is enabled).
     */
    public function backupAllTenants(array $options = []): array
    {
        $results = [];

        foreach ($this->tenancy->allTenants() as $tenant) {
            try {
                if (config('vanguard.queue.enabled', true)) {
                    \SoftArtisan\Vanguard\Jobs\RunTenantBackupJob::dispatch($tenant->getTenantKey(), $options)
                        ->onQueue(config('vanguard.queue.queue', 'vanguard'))
                        ->onConnection(config('vanguard.queue.connection'));
                    $results[] = ['tenant' => $tenant->getTenantKey(), 'queued' => true];
                } else {
                    $record    = $this->backupTenant($tenant, $options);
                    $results[] = ['tenant' => $tenant->getTenantKey(), 'record' => $record];
                }
            } catch (\Throwable $e) {
                Log::error('[Vanguard] Tenant backup failed, skipping', [
                    'tenant' => $tenant->getTenantKey(),
                    'error'  => $e->getMessage(),
                ]);
                $results[] = ['tenant' => $tenant->getTenantKey(), 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────

    protected function createRecord(?string $tenantId, string $type, array $meta = []): BackupRecord
    {
        return BackupRecord::create([
            'tenant_id'   => $tenantId,
            'type'        => $type,
            'status'      => 'running',
            'started_at'  => now(),
            'sources'     => array_keys(array_filter([
                'landlord_database' => config('vanguard.sources.landlord_database'),
                'tenant_databases'  => config('vanguard.sources.tenant_databases'),
                'filesystem'        => config('vanguard.sources.filesystem'),
            ])),
            'destinations' => array_keys(array_filter([
                'local'  => config('vanguard.destinations.local.enabled'),
                'remote' => config('vanguard.destinations.remote.enabled'),
            ])),
            'meta' => $meta,
        ]);
    }

    protected function completeRecord(BackupRecord $record, array $bundle): void
    {
        $record->update([
            'status'       => 'completed',
            'file_path'    => $bundle['local_path'],
            'remote_path'  => $bundle['remote_path'],
            'file_size'    => $bundle['size'],
            'checksum'     => $bundle['checksum'],
            'completed_at' => now(),
        ]);
    }

    protected function failRecord(BackupRecord $record, \Throwable $e): void
    {
        $record->update([
            'status'       => 'failed',
            'error'        => $e->getMessage(),
            'completed_at' => now(),
        ]);
        Log::error('[Vanguard] Backup failed', [
            'record_id' => $record->id,
            'error'     => $e->getMessage(),
        ]);
    }
}
