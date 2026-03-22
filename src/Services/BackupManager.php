<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\BackupStarted;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class BackupManager
{
    /**
     * @param  DatabaseDriver       $db
     * @param  StorageDriver        $storage
     * @param  BackupStorageManager $store
     * @param  TenancyResolver      $tenancy
     */
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
     *
     * Creates a BackupRecord, fires BackupStarted, runs all configured sources,
     * bundles the output, then fires BackupCompleted or BackupFailed.
     * The tmp directory is always cleaned up in a finally block.
     *
     * @param  array  $options  Supported keys:
     *                          - 'include_filesystem' (bool) — default true
     * @return BackupRecord  A freshly reloaded record with final status
     *
     * @throws \Throwable  Re-throws any exception after recording the failure
     */
    public function backupLandlord(array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

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
     *
     * Initialises the tenancy context via TenancyResolver::runForTenant() to
     * ensure the correct database connection is active during the dump.
     *
     * @param  mixed  $tenant   A tenant model instance (must implement getTenantKey())
     * @param  array  $options  Supported keys:
     *                          - 'include_filesystem' (bool) — default false for tenant backups
     * @return BackupRecord  A freshly reloaded record with final status
     *
     * @throws \Throwable  Re-throws any exception after recording the failure
     */
    public function backupTenant(mixed $tenant, array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

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
     *
     * Useful for backing up uploaded files independently of the database schedule.
     *
     * @param  array  $options  Reserved for future use
     * @return BackupRecord  A freshly reloaded record with final status
     *
     * @throws \Throwable  Re-throws any exception after recording the failure
     */
    public function backupFilesystem(array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

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
     *
     * When the queue is enabled, each tenant backup is dispatched as a
     * RunTenantBackupJob. Individual tenant failures are caught and logged
     * without halting the remaining tenants.
     *
     * @param  array  $options  Forwarded to backupTenant() or RunTenantBackupJob
     * @return array  One entry per tenant: ['tenant' => id, 'queued' => true]
     *                or ['tenant' => id, 'record' => BackupRecord]
     *                or ['tenant' => id, 'error' => string]
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

    /**
     * Ensure there is at least 100 MB of free space in the tmp directory before starting a backup.
     *
     * Logs a warning if the free space cannot be determined (e.g. unsupported filesystem)
     * and allows the backup to proceed. Only throws when free space is definitively too low.
     *
     * @param  int  $minFreeMb  Minimum required free space in megabytes (default: 100)
     *
     * @throws RuntimeException If free space is below the required minimum
     */
    protected function assertSufficientDiskSpace(int $minFreeMb = 100): void
    {
        $tmpPath = config('vanguard.tmp_path', storage_path('vanguard-tmp'));

        // Use the parent directory if the tmp dir doesn't exist yet.
        $checkPath = is_dir($tmpPath) ? $tmpPath : dirname($tmpPath);

        $freeBytes = @disk_free_space($checkPath);

        if ($freeBytes === false) {
            Log::warning('[Vanguard] Could not determine free disk space', ['path' => $checkPath]);
            return;
        }

        $minFreeBytes = $minFreeMb * 1024 * 1024;

        if ($freeBytes < $minFreeBytes) {
            throw new RuntimeException(sprintf(
                '[Vanguard] Insufficient disk space: %.1f MB free, %d MB required in %s',
                $freeBytes / 1024 / 1024,
                $minFreeMb,
                $checkPath,
            ));
        }
    }

    /**
     * Create and persist a new BackupRecord in 'running' status.
     *
     * @param  string|null  $tenantId  Null for landlord/filesystem backups
     * @param  string       $type      'landlord'|'tenant'|'filesystem'
     * @param  array        $meta      Arbitrary options stored on the record
     * @return BackupRecord
     */
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
                'ftp'    => config('vanguard.destinations.ftp.enabled'),
            ])),
            'meta' => $meta,
        ]);
    }

    /**
     * Update a BackupRecord to 'completed' status with bundle metadata.
     *
     * @param  BackupRecord  $record
     * @param  array         $bundle  Output of BackupStorageManager::bundle()
     */
    protected function completeRecord(BackupRecord $record, array $bundle): void
    {
        $record->update([
            'status'       => 'completed',
            'file_path'    => $bundle['local_path'],
            'remote_path'  => $bundle['remote_path'],
            'ftp_path'     => $bundle['ftp_path'],
            'file_size'    => $bundle['size'],
            'checksum'     => $bundle['checksum'],
            'completed_at' => now(),
        ]);
    }

    /**
     * Update a BackupRecord to 'failed' status and log the error.
     *
     * @param  BackupRecord  $record
     * @param  \Throwable    $e
     */
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
