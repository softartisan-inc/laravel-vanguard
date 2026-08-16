<?php

namespace SoftArtisan\Vanguard\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\BackupStarted;
use SoftArtisan\Vanguard\Jobs\RunTenantBackupJob;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\Drivers\DatabaseDriver;
use SoftArtisan\Vanguard\Services\Drivers\StorageDriver;

class BackupManager
{
    public function __construct(
        protected DatabaseDriver $db,
        protected StorageDriver $storage,
        protected BackupStorageManager $store,
        protected TenancyResolver $tenancy,
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
     * @return BackupRecord A freshly reloaded record with final status
     *
     * @throws \Throwable Re-throws any exception after recording the failure
     */
    public function backupLandlord(array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

        $record = $this->createRecord(null, 'landlord', $options);
        $emptyFilesystem = null;

        try {
            event(new BackupStarted($record));

            $files = [];
            $name = "landlord_{$record->id}_".now()->format('Ymd_His');

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
                $files['storage'] = $this->archiveStorage(
                    $this->store->tmpPath("{$name}_fs.tar.gz"),
                    $emptyFilesystem,
                );
            }

            $this->reportEmptyFilesystem($record, $emptyFilesystem);

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
     * @param  mixed  $tenant  A tenant model instance (must implement getTenantKey())
     * @param  array  $options  Supported keys:
     *                          - 'include_filesystem' (bool) — default false for tenant backups
     * @return BackupRecord A freshly reloaded record with final status
     *
     * @throws \Throwable Re-throws any exception after recording the failure
     */
    public function backupTenant(mixed $tenant, array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

        $tenantId = $tenant->getTenantKey();
        $record = $this->createRecord($tenantId, 'tenant', $options);
        $emptyFilesystem = null;

        try {
            event(new BackupStarted($record));

            $this->tenancy->runForTenant($tenant, function () use ($tenant, $record, $options, &$files, &$name, &$emptyFilesystem) {
                $files = [];
                $name = "tenant_{$tenant->getTenantKey()}_{$record->id}_".now()->format('Ymd_His');

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
                    // Resolved inside the tenancy window on purpose:
                    // stancl/tenancy has swapped storage_path() to the
                    // tenant's own root, which is the root the diagnosis has
                    // to name.
                    $files['storage'] = $this->archiveStorage(
                        $this->store->tmpPath("{$name}_fs.tar.gz"),
                        $emptyFilesystem,
                    );
                }
            });

            // Outside the window: the record lives in the central database,
            // and writing it while the connection is swapped would look for
            // vanguard_backups in the tenant's own.
            $this->reportEmptyFilesystem($record, $emptyFilesystem);

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
     * @return BackupRecord A freshly reloaded record with final status
     *
     * @throws \Throwable Re-throws any exception after recording the failure
     */
    public function backupFilesystem(array $options = []): BackupRecord
    {
        $this->assertSufficientDiskSpace();

        $record = $this->createRecord(null, 'filesystem', $options);
        $emptyFilesystem = null;

        try {
            event(new BackupStarted($record));

            $name = "filesystem_{$record->id}_".now()->format('Ymd_His');

            $files['storage'] = $this->archiveStorage(
                $this->store->tmpPath("{$name}_fs.tar.gz"),
                $emptyFilesystem,
            );

            $this->reportEmptyFilesystem($record, $emptyFilesystem);

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
     * @return array One entry per tenant: ['tenant' => id, 'queued' => true]
     *               or ['tenant' => id, 'record' => BackupRecord]
     *               or ['tenant' => id, 'error' => string]
     */
    public function backupAllTenants(array $options = []): array
    {
        $results = [];

        foreach ($this->tenancy->allTenants() as $tenant) {
            try {
                if (config('vanguard.queue.enabled', true)) {
                    RunTenantBackupJob::dispatch($tenant->getTenantKey(), $options)
                        ->onQueue(config('vanguard.queue.queue', 'vanguard'))
                        ->onConnection(config('vanguard.queue.connection'));
                    $results[] = ['tenant' => $tenant->getTenantKey(), 'queued' => true];
                } else {
                    $record = $this->backupTenant($tenant, $options);
                    $results[] = ['tenant' => $tenant->getTenantKey(), 'record' => $record];
                }
            } catch (\Throwable $e) {
                Log::error('[Vanguard] Tenant backup failed, skipping', [
                    'tenant' => $tenant->getTenantKey(),
                    'error' => $e->getMessage(),
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
     * Archive the configured filesystem paths, and never do it silently when
     * there is nothing to archive.
     *
     * A backup asked for the filesystem whose paths all resolve to nothing
     * produces a valid, tiny, *empty* tarball. Observed for real on a preprod
     * tenant: `vanguard.sources.filesystem_paths` is ['app'], stancl/tenancy
     * swaps storage_path() to the tenant's own root for the duration of the
     * backup, and that root had no 'app' directory — so nothing was archived,
     * and nothing anywhere said so. An archive that looks healthy, weighs
     * almost nothing and restores nothing is the failure this package exists
     * to abolish; a tenant whose storage layout does not match the
     * configuration is invisibly unprotected, and the health screen's
     * freshness goes green on it.
     *
     * The emptiness is handed back rather than recorded here: this runs inside
     * the tenancy window for a tenant backup, where the database connection is
     * the tenant's own and Vanguard's tables are not.
     *
     * @param  string  $destination  Absolute path for the output .tar.gz
     * @param  array|null  $empty  Set to the diagnosis when nothing resolved
     * @return string Path to the created archive
     *
     * @throws RuntimeException When on_empty_filesystem is 'fail'
     */
    protected function archiveStorage(string $destination, ?array &$empty): string
    {
        $paths = $this->storage->resolveBackupPaths();

        if ($paths === []) {
            $empty = [
                'configured_paths' => array_values(array_map(
                    fn ($path) => (string) $path,
                    (array) config('vanguard.sources.filesystem_paths', ['app']),
                )),
                'storage_root' => rtrim(storage_path(), DIRECTORY_SEPARATOR),
            ];

            // Deliberately not the default: a landlord installation that
            // genuinely keeps no file under storage/app is legitimate, and
            // turning a working setup into a failing one on upgrade would be
            // worse than the silence being fixed here.
            if (strtolower((string) config('vanguard.sources.on_empty_filesystem', 'warn')) === 'fail') {
                throw new RuntimeException(sprintf(
                    '[Vanguard] The filesystem backup resolved no existing path under [%s]. '
                    .'Configured: [%s]. Check vanguard.sources.filesystem_paths against this target\'s storage layout.',
                    $empty['storage_root'],
                    implode(', ', $empty['configured_paths']),
                ));
            }
        }

        return $this->storage->archive(
            paths: $paths,
            exclude: $this->storage->resolveExcludePaths(),
            destination: $destination,
        );
    }

    /**
     * Say — in the log and on the record — that this archive carries no file.
     *
     * The record is where it matters: an operator reads a list of green rows,
     * not a log file, and the dashboard and the API can only report what the
     * row holds.
     *
     * @param  array|null  $diagnosis  Null when the filesystem was not empty,
     *                                 or was never asked for
     */
    protected function reportEmptyFilesystem(BackupRecord $record, ?array $diagnosis): void
    {
        if ($diagnosis === null) {
            return;
        }

        Log::warning('[Vanguard] The filesystem backup resolved no existing path: this archive carries no file', [
            'record_id' => $record->id,
            'target' => $record->tenant_id !== null ? "tenant [{$record->tenant_id}]" : $record->type,
            'configured_paths' => $diagnosis['configured_paths'],
            'storage_root' => $diagnosis['storage_root'],
        ]);

        $record->meta = array_merge((array) $record->meta, [
            'filesystem_empty' => true,
            'filesystem_paths' => $diagnosis['configured_paths'],
            'storage_root' => $diagnosis['storage_root'],
        ]);

        $record->save();
    }

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
     * @param  string  $type  'landlord'|'tenant'|'filesystem'
     * @param  array  $meta  Arbitrary options stored on the record
     */
    protected function createRecord(?string $tenantId, string $type, array $meta = []): BackupRecord
    {
        return BackupRecord::create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'status' => 'running',
            'started_at' => now(),
            'sources' => array_keys(array_filter([
                'landlord_database' => config('vanguard.sources.landlord_database'),
                'tenant_databases' => config('vanguard.sources.tenant_databases'),
                'filesystem' => config('vanguard.sources.filesystem'),
            ])),
            'destinations' => array_keys(array_filter([
                'local' => config('vanguard.destinations.local.enabled'),
                'remote' => config('vanguard.destinations.remote.enabled'),
                'ftp' => config('vanguard.destinations.ftp.enabled'),
            ])),
            'meta' => $meta,
        ]);
    }

    /**
     * Update a BackupRecord to 'completed' status with bundle metadata.
     *
     * @param  array  $bundle  Output of BackupStorageManager::bundle()
     */
    protected function completeRecord(BackupRecord $record, array $bundle): void
    {
        $this->finishRecord($record, [
            'status' => 'completed',
            'file_path' => $bundle['local_path'],
            'remote_path' => $bundle['remote_path'],
            'ftp_path' => $bundle['ftp_path'],
            'file_size' => $bundle['size'],
            'checksum' => $bundle['checksum'],
            'completed_at' => now(),
        ]);
    }

    /**
     * Update a BackupRecord to 'failed' status and log the error.
     */
    protected function failRecord(BackupRecord $record, \Throwable $e): void
    {
        $recorded = $this->finishRecord($record, [
            'status' => 'failed',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        Log::error('[Vanguard] Backup failed', [
            'record_id' => $record->id,
            'error' => $e->getMessage(),
            // False when the row had already reached a final status: the
            // failure is real for this attempt, but it is not the record's
            // outcome and must not be written over it.
            'recorded' => $recorded,
        ]);
    }

    /**
     * Write a final status onto a record, but only while it is still running.
     *
     * The status is read from the row inside the same statement, not from the
     * copy in hand: a worker that has been holding this model since before a
     * retry finished still believes it is running. Without the condition, the
     * slower of two attempts has the last word and a completed backup is
     * reported as failed — which, with --queue dispatching for real, is a
     * live race rather than a theoretical one.
     *
     * @param  array<string, mixed>  $attributes  The final status and its metadata
     * @return bool Whether the row was actually written
     */
    protected function finishRecord(BackupRecord $record, array $attributes): bool
    {
        $written = $record->newQuery()
            ->whereKey($record->getKey())
            ->where('status', 'running')
            ->update($attributes) > 0;

        if ($written) {
            $record->refresh();
        }

        return $written;
    }
}
