<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

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
        $totalTenants = $this->tenancy->isEnabled() ? $this->tenancy->allTenants()->count() : 0;
        $totalBackups = BackupRecord::count();
        $runningBackups = BackupRecord::running()->count();
        $failedBackups = BackupRecord::failed()->where('created_at', '>=', now()->subDay())->count();
        $totalSize = BackupRecord::completed()->sum('file_size');

        $recentBackups = BackupRecord::latest()
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

        $query = BackupRecord::latest();

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

        $all = $this->tenancy->allTenants();
        $keys = $all->map(fn ($tenant) => (string) $tenant->getTenantKey())->all();

        // One aggregation for every tenant, then one read of the rows it
        // named. The loop used to run two queries per tenant, so the cost of
        // the screen that says whether backups work grew with the customer
        // list — MAX(id) stands in for "the latest" because ids are handed out
        // in creation order and two records created in the same second have no
        // other ordering to offer.
        $stats = BackupRecord::query()
            ->selectRaw('tenant_id, COUNT(*) as total, MAX(id) as latest_id')
            ->whereIn('tenant_id', $keys)
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $latest = BackupRecord::query()
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
     * Trigger a backup. The 'type' parameter determines what is backed up.
     * When the queue is enabled, jobs are dispatched and the response indicates queuing.
     *
     * @param  Request  $request  Validated fields: type (required), tenant_id (required for 'tenant')
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:landlord,tenant,all-tenants,filesystem',
            'tenant_id' => 'required_if:type,tenant|nullable|string',
        ]);

        try {
            switch ($request->type) {
                case 'landlord':
                    if (config('vanguard.queue.enabled', true)) {
                        RunTenantBackupJob::dispatch('__landlord__', [])
                            ->onConnection(config('vanguard.queue.connection'))
                            ->onQueue(config('vanguard.queue.queue', 'vanguard'));

                        return response()->json(['message' => 'Landlord backup queued.', 'queued' => true]);
                    }
                    $record = $this->manager->backupLandlord();

                    return response()->json(['record' => $this->formatRecord($record)]);

                case 'tenant':
                    $tenant = $this->tenancy->findTenant($request->tenant_id);
                    if (config('vanguard.queue.enabled', true)) {
                        RunTenantBackupJob::dispatch($request->tenant_id)
                            ->onConnection(config('vanguard.queue.connection'))
                            ->onQueue(config('vanguard.queue.queue', 'vanguard'));

                        return response()->json(['message' => 'Tenant backup queued.', 'queued' => true]);
                    }
                    $record = $this->manager->backupTenant($tenant);

                    return response()->json(['record' => $this->formatRecord($record)]);

                case 'all-tenants':
                    $results = $this->manager->backupAllTenants();

                    return response()->json(['results' => $results]);

                case 'filesystem':
                    $record = $this->manager->backupFilesystem();

                    return response()->json(['record' => $this->formatRecord($record)]);
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
     * DELETE /vanguard/api/backups/{id}
     *
     * Delete a backup record and its associated files from local and remote disks.
     *
     * @param  int  $id  BackupRecord primary key
     */
    public function destroy(int $id): JsonResponse
    {
        $record = BackupRecord::findOrFail($id);

        try {
            if ($record->file_path) {
                $disk = config('vanguard.destinations.local.disk', 'local');
                Storage::disk($disk)->delete($record->file_path);
            }
            if ($record->remote_path) {
                $disk = config('vanguard.destinations.remote.disk', 's3');
                Storage::disk($disk)->delete($record->remote_path);
            }
            if ($record->ftp_path) {
                $disk = config('vanguard.destinations.ftp.disk', 'ftp');
                Storage::disk($disk)->delete($record->ftp_path);
            }
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
