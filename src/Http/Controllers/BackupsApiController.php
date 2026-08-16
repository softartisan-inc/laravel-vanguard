<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SoftArtisan\Vanguard\Http\Concerns\GuardsDestructiveActions;
use SoftArtisan\Vanguard\Jobs\RunRestoreJob;
use SoftArtisan\Vanguard\Jobs\RunTenantBackupJob;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Vanguard;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupsApiController extends Controller
{
    use GuardsDestructiveActions;

    public function __construct(
        protected BackupManager $manager,
        protected TenancyResolver $tenancy,
        protected BackupStorageManager $store,
    ) {}

    /**
     * GET /vanguard/api/stats
     *
     * Return aggregated dashboard statistics: tenant count, backup counts by
     * status, total storage used, and the ten most recent backup records.
     */
    public function stats(): JsonResponse
    {
        $central = Vanguard::centralConnection();

        $totalTenants = $this->tenancy->isEnabled() ? $this->tenancy->allTenants()->count() : 0;
        $totalBackups = BackupRecord::on($central)->count();
        $runningBackups = BackupRecord::on($central)->running()->count();
        $failedBackups = BackupRecord::on($central)->failed()->where('created_at', '>=', now()->subDay())->count();
        $totalSize = BackupRecord::on($central)->completed()->sum('file_size');

        $recentBackups = BackupRecord::on($central)->latest()
            ->limit(10)
            ->get()
            ->map(fn ($r) => $this->formatRecord($r));

        return response()->json([
            'total_tenants' => $totalTenants,
            'total_backups' => $totalBackups,
            'running_backups' => $runningBackups,
            'failed_recent' => $failedBackups,
            'total_size_bytes' => $totalSize,
            'total_size_human' => $this->humanSize($totalSize),
            'recent_backups' => $recentBackups,
        ]);
    }

    /**
     * GET /vanguard/api/backups
     *
     * Return a paginated list of backup records. Supports filtering by
     * tenant_id, status, and type. All filter parameters are validated.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,running,completed,failed',
            'type' => 'nullable|in:landlord,tenant,filesystem',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = BackupRecord::on(Vanguard::centralConnection())->latest();

        if ($tenantId = $request->get('tenant_id')) {
            $query->forTenant($tenantId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $records = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => collect($records->items())->map(fn ($r) => $this->formatRecord($r)),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * GET /vanguard/api/tenants
     *
     * Return all tenants with their latest backup record and total backup count.
     * Returns an empty list when multi-tenancy is disabled.
     */
    public function tenants(): JsonResponse
    {
        if (! $this->tenancy->isEnabled()) {
            return response()->json(['tenants' => []]);
        }

        $central = Vanguard::centralConnection();

        $all = $this->tenancy->allTenants();
        $keys = $all->map(fn ($tenant) => (string) $tenant->getTenantKey())->all();

        // One aggregation for every tenant, then one read of the rows it
        // named. The loop used to run two queries per tenant, so the cost of
        // the screen that says whether backups work grew with the customer
        // list — MAX(id) stands in for "the latest" because ids are handed out
        // in creation order and two records created in the same second have no
        // other ordering to offer.
        $stats = BackupRecord::on($central)
            ->selectRaw('tenant_id, COUNT(*) as total, MAX(id) as latest_id')
            ->whereIn('tenant_id', $keys)
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $latest = BackupRecord::on($central)
            ->whereIn('id', $stats->pluck('latest_id')->all())
            ->get()
            ->keyBy('id');

        $tenants = $all->map(function ($tenant) use ($stats, $latest) {
            $key = (string) $tenant->getTenantKey();
            $row = $stats->get($key);
            $latestBackup = $row ? $latest->get($row->latest_id) : null;

            return [
                'id' => $key,
                'schedule' => $this->tenancy->tenantSchedule($tenant),
                'latest_backup' => $latestBackup ? $this->formatRecord($latestBackup) : null,
                'total_backups' => (int) ($row->total ?? 0),
            ];
        });

        return response()->json(['tenants' => $tenants]);
    }

    /**
     * POST /vanguard/api/backups/run
     *
     * Trigger a backup. Every option `vanguard:backup` accepts is reachable
     * here: the target (type / tenant_id), whether the filesystem comes along
     * (include_filesystem, i.e. --no-filesystem), and whether the work is
     * queued regardless of configuration (queue, i.e. --queue).
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:landlord,tenant,all-tenants,filesystem',
            'tenant_id' => 'required_if:type,tenant|nullable|string',
            'include_filesystem' => 'nullable|boolean',
            'queue' => 'nullable|boolean',
        ]);

        // The options array the endpoint used to drop on the floor: it
        // dispatched [] and called the manager with no arguments, so
        // --no-filesystem simply had no equivalent from the dashboard.
        $options = ['include_filesystem' => $request->boolean('include_filesystem', true)];

        // Parity with --queue: an explicit value wins over the configuration,
        // in both directions. Absent, the configuration decides as before.
        $queued = $request->has('queue')
            ? $request->boolean('queue')
            : (bool) config('vanguard.queue.enabled', true);

        try {
            switch ($request->type) {
                case 'landlord':
                    return $this->dispatchOrRun(
                        '__landlord__',
                        $options,
                        $queued,
                        fn () => $this->manager->backupLandlord($options),
                        'Landlord backup queued.',
                    );

                case 'tenant':
                    $tenant = $this->tenancy->findTenant($request->tenant_id);

                    return $this->dispatchOrRun(
                        (string) $request->tenant_id,
                        $options,
                        $queued,
                        fn () => $this->manager->backupTenant($tenant, $options),
                        'Tenant backup queued.',
                    );

                case 'filesystem':
                    return $this->dispatchOrRun(
                        '__filesystem__',
                        $options,
                        $queued,
                        fn () => $this->manager->backupFilesystem($options),
                        'Filesystem backup queued.',
                    );

                case 'all-tenants':
                    // backupAllTenants() consults vanguard.queue.enabled itself
                    // and dispatches one job per tenant, so it is not routed
                    // through dispatchOrRun(): there is no single job to push.
                    return response()->json(['results' => $this->manager->backupAllTenants($options)]);
            }
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Backup run failed', [
                'type' => $request->type,
                'tenant_id' => $request->tenant_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Backup operation failed. Check server logs for details.'], 500);
        }

        return response()->json(['error' => 'Invalid type'], 422);
    }

    /**
     * Queue one backup job, or run it inline and return the record.
     *
     * @param  string  $target  Tenant key, or the '__landlord__' / '__filesystem__' sentinel
     * @param  Closure  $inline  Produces the BackupRecord when nothing is queued
     */
    protected function dispatchOrRun(
        string $target,
        array $options,
        bool $queued,
        Closure $inline,
        string $queuedMessage,
    ): JsonResponse {
        if ($queued) {
            RunTenantBackupJob::dispatch($target, $options)
                ->onConnection(config('vanguard.queue.connection'))
                ->onQueue(config('vanguard.queue.queue', 'vanguard'));

            return response()->json(['message' => $queuedMessage, 'queued' => true]);
        }

        return response()->json(['record' => $this->formatRecord($inline())]);
    }

    /**
     * DELETE /vanguard/api/backups/{id}
     *
     * Delete a backup record and its associated files from local and remote disks.
     *
     * @param  int  $id  BackupRecord primary key
     */
    public function destroy(int $id): JsonResponse
    {
        $record = BackupRecord::on(Vanguard::centralConnection())->findOrFail($id);

        try {
            if ($record->file_path) {
                Storage::disk($this->diskFor('local'))->delete($record->file_path);
            }
            if ($record->remote_path) {
                Storage::disk($this->diskFor('remote'))->delete($record->remote_path);
            }
            if ($record->ftp_path) {
                Storage::disk($this->diskFor('ftp'))->delete($record->ftp_path);
            }

            $this->trace('backup deleted', $record->tenant_id ?? $record->type, [
                'backup_id' => $record->id,
                'destinations' => array_keys(array_filter([
                    'local' => $record->file_path,
                    'remote' => $record->remote_path,
                    'ftp' => $record->ftp_path,
                ])),
            ]);

            $record->delete();
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Backup deletion failed', [
                'backup_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to delete backup. Check server logs for details.'], 500);
        }

        return response()->json(['message' => 'Backup deleted successfully.']);
    }

    /**
     * GET /vanguard/api/backups/{id}/download
     *
     * Stream the archive from the destination that holds it.
     *
     * Streamed, never loaded: a landlord archive is gigabytes, and reading it
     * into a PHP string would take the process down. Storage::download()
     * builds the response on readStream().
     */
    public function download(int $id, Request $request): StreamedResponse|JsonResponse
    {
        // Built by hand rather than $request->validate(), the same reason
        // GuardsDestructiveActions::rejectUnlessConfirmed() does: validate()
        // only answers 422 when the request expects JSON, and a direct
        // browser navigation to a download link — this endpoint's normal
        // invocation, not an edge case — sends no Accept: application/json
        // and would otherwise be redirected instead of told the value is bad.
        $source = $request->input('source');

        if ($source !== null && ! in_array($source, ['local', 'remote', 'ftp'], true)) {
            return response()->json([
                'error' => "The selected source [{$source}] is invalid.",
            ], 422);
        }

        $record = BackupRecord::on(Vanguard::centralConnection())->find($id);

        if ($record === null) {
            return response()->json(['error' => "Backup #{$id} not found."], 404);
        }

        // Ordered local → remote → ftp, and filtered to the destinations this
        // backup actually reached. No default of 'local': on the recommended
        // production setup local is disabled and only the remote copy exists.
        $paths = array_filter([
            'local' => $record->file_path,
            'remote' => $record->remote_path,
            'ftp' => $record->ftp_path,
        ]);

        $source = $source ?? array_key_first($paths);

        if ($source === null || ! isset($paths[$source])) {
            return response()->json([
                'error' => $paths === []
                    ? "Backup #{$record->id} reached no destination and has nothing to download."
                    : "Backup #{$record->id} has no copy on [{$source}]. Available: "
                        .implode(', ', array_keys($paths)).'.',
            ], 400);
        }

        $disk = $this->diskFor($source);

        if (! Storage::disk($disk)->exists($paths[$source])) {
            return response()->json([
                'error' => "Backup #{$record->id} is recorded on [{$source}] but the archive is "
                    ."no longer present on disk [{$disk}].",
            ], 404);
        }

        // Traced before the stream opens: this takes every tenant's database,
        // personal data and all, off the server (spec §7).
        $this->trace('backup downloaded', $record->tenant_id ?? $record->type, [
            'backup_id' => $record->id,
            'source' => $source,
            'path' => $paths[$source],
        ]);

        return Storage::disk($disk)->download($paths[$source], basename($paths[$source]));
    }

    /**
     * The filesystem disk backing a Vanguard destination.
     *
     * @param  string  $destination  'local' | 'remote' | 'ftp'
     */
    protected function diskFor(string $destination): string
    {
        return match ($destination) {
            'remote' => config('vanguard.destinations.remote.disk', 's3'),
            'ftp' => config('vanguard.destinations.ftp.disk', 'ftp'),
            default => config('vanguard.destinations.local.disk', 'local'),
        };
    }

    /**
     * POST /vanguard/api/backups/{id}/restore
     *
     * Create a history row and queue the restore, answering 202 with its id.
     *
     * This replaces the synchronous implementation outright. That one ran a
     * multi-minute operation inside the request, lost its answer to any proxy
     * timeout while the server carried on, wrote no history, and hid the exact
     * error behind "check server logs". Keeping both paths would have left two
     * ways to restore with two different levels of observability — the exact
     * mistake this phase exists to remove.
     *
     * @return JsonResponse 202 with the restore id, or 400 / 404 on refusal
     */
    public function restore(int $id, Request $request): JsonResponse
    {
        // Checked first, and on presence rather than value. Replace mode does
        // not destroy what the backup contains — it destroys what the backup
        // does not contain, with no way back — and stays a console decision
        // (spec §2, §7). A caller sending wipe_storage=false is told the
        // parameter has no meaning here rather than silently obeyed.
        if ($request->has('wipe_storage')) {
            return response()->json([
                'error' => 'Replace mode is not available from the API. Run '
                    .'php artisan vanguard:restore '.$id.' --restore-storage --wipe-storage on the server.',
            ], 400);
        }

        // Same rule, same reason. Redirecting a restore into a throwaway
        // database is a rehearsal an operator performs in front of the machine;
        // accepting the parameter here and quietly ignoring it would let a
        // caller believe they were writing to a scratch database while the
        // restore overwrote the real one — the worst outcome this option has,
        // and precisely what the console shouts about before it proceeds.
        if ($request->has('database')) {
            return response()->json([
                'error' => 'Redirecting a restore to another database is not available from the API. Run '
                    .'php artisan vanguard:restore '.$id.' --database=<name> on the server.',
            ], 400);
        }

        $request->validate([
            'source' => 'nullable|in:local,remote,ftp',
            'restore_db' => 'nullable|boolean',
            'restore_storage' => 'nullable|boolean',
            'verify_checksum' => 'nullable|boolean',
        ]);

        $central = Vanguard::centralConnection();

        $record = BackupRecord::on($central)->find($id);

        if ($record === null) {
            return response()->json(['error' => "Backup #{$id} not found."], 404);
        }

        // What the operator has to type back: the tenant they are about to
        // overwrite, or 'landlord' / 'filesystem' for the untenanted targets.
        $target = $record->tenant_id ?? $record->type;

        if ($rejection = $this->rejectUnlessConfirmed($request, $target)) {
            return $rejection;
        }

        // 400 rather than 409: one convention for every business refusal.
        if (! $record->isCompleted()) {
            return response()->json([
                'error' => "Backup #{$record->id} is [{$record->status}] and cannot be restored.",
            ], 400);
        }

        $restore = RestoreRecord::on($central)->create([
            'backup_id' => $record->id,
            // Copied, not looked up: the history has to survive the deletion
            // of the backup it restored.
            'type' => $record->type,
            'tenant_id' => $record->tenant_id,
            'backup_created_at' => $record->created_at,
            // No default: the service picks the first destination the backup
            // actually reached. Forcing 'local' broke restores on the
            // recommended production setup, where only the remote copy exists.
            'source' => $request->input('source'),
            'restore_db' => $request->boolean('restore_db', true),
            'restore_storage' => $request->boolean('restore_storage', false),
            'verify_checksum' => $request->boolean('verify_checksum', true),
            'status' => 'pending',
            'requested_by' => Vanguard::actor(),
        ]);

        $this->trace('restore requested', $target, [
            'restore_id' => $restore->id,
            'backup_id' => $record->id,
            'source' => $restore->source,
            'restore_db' => $restore->restore_db,
            'restore_storage' => $restore->restore_storage,
        ]);

        // Dispatched unconditionally, even when vanguard.queue.enabled is
        // false. A restore that never starts for want of a worker stays
        // visible as a pending row the health screen counts, which is a far
        // better failure than a request that blocks for seven minutes
        // (spec §12).
        RunRestoreJob::dispatch($restore->id)
            ->onConnection(config('vanguard.queue.connection'))
            ->onQueue(config('vanguard.queue.queue', 'vanguard'));

        return response()->json([
            'restore_id' => $restore->id,
            'status' => 'pending',
        ], 202);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Serialize a BackupRecord to an array suitable for JSON output.
     *
     * @return array<string, mixed>
     */
    protected function formatRecord(BackupRecord $r): array
    {
        return [
            'id' => $r->id,
            'tenant_id' => $r->tenant_id,
            'type' => $r->type,
            'status' => $r->status,
            'file_size' => $r->file_size,
            'file_size_human' => $r->file_size_human,
            'duration' => $r->duration,
            'checksum' => $r->checksum,
            'destinations' => $r->destinations,
            'ftp_path' => $r->ftp_path,
            'error' => $r->error,
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }

    /**
     * Convert a byte count to a human-readable string (e.g. "4.2 MB").
     */
    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2).' '.$units[$unit];
    }
}
