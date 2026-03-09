<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\RestoreService;
use SoftArtisan\Vanguard\Services\TenancyResolver;

class BackupsApiController extends Controller
{
    public function __construct(
        protected BackupManager       $manager,
        protected TenancyResolver     $tenancy,
        protected BackupStorageManager $store,
    ) {}

    // GET /vanguard/api/stats
    public function stats(): JsonResponse
    {
        $totalTenants   = $this->tenancy->isEnabled() ? $this->tenancy->allTenants()->count() : 0;
        $totalBackups   = BackupRecord::count();
        $runningBackups = BackupRecord::running()->count();
        $failedBackups  = BackupRecord::failed()->where('created_at', '>=', now()->subDay())->count();
        $totalSize      = BackupRecord::completed()->sum('file_size');

        $recentBackups = BackupRecord::latest()
            ->limit(10)
            ->get()
            ->map(fn ($r) => $this->formatRecord($r));

        return response()->json([
            'total_tenants'    => $totalTenants,
            'total_backups'    => $totalBackups,
            'running_backups'  => $runningBackups,
            'failed_recent'    => $failedBackups,
            'total_size_bytes' => $totalSize,
            'total_size_human' => $this->humanSize($totalSize),
            'recent_backups'   => $recentBackups,
        ]);
    }

    // GET /vanguard/api/backups
    public function index(Request $request): JsonResponse
    {
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
            'data'  => collect($records->items())->map(fn ($r) => $this->formatRecord($r)),
            'meta'  => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    // GET /vanguard/api/tenants
    public function tenants(): JsonResponse
    {
        if (! $this->tenancy->isEnabled()) {
            return response()->json(['tenants' => []]);
        }

        $tenants = $this->tenancy->allTenants()->map(function ($tenant) {
            $latestBackup = BackupRecord::forTenant($tenant->getTenantKey())->latest()->first();
            return [
                'id'             => $tenant->getTenantKey(),
                'schedule'       => $this->tenancy->tenantSchedule($tenant),
                'latest_backup'  => $latestBackup ? $this->formatRecord($latestBackup) : null,
                'total_backups'  => BackupRecord::forTenant($tenant->getTenantKey())->count(),
            ];
        });

        return response()->json(['tenants' => $tenants]);
    }

    // POST /vanguard/api/backups/run
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => 'required|in:landlord,tenant,all-tenants,filesystem',
            'tenant_id' => 'required_if:type,tenant|nullable|string',
        ]);

        try {
            switch ($request->type) {
                case 'landlord':
                    if (config('vanguard.queue.enabled', true)) {
                        \SoftArtisan\Vanguard\Jobs\RunTenantBackupJob::dispatch('__landlord__', [])
                            ->onQueue(config('vanguard.queue.queue', 'vanguard'));
                        return response()->json(['message' => 'Landlord backup queued.', 'queued' => true]);
                    }
                    $record = $this->manager->backupLandlord();
                    return response()->json(['record' => $this->formatRecord($record)]);

                case 'tenant':
                    $tenant = $this->tenancy->findTenant($request->tenant_id);
                    if (config('vanguard.queue.enabled', true)) {
                        \SoftArtisan\Vanguard\Jobs\RunTenantBackupJob::dispatch($request->tenant_id)
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
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['error' => 'Invalid type'], 422);
    }

    // DELETE /vanguard/api/backups/{id}
    public function destroy(int $id): JsonResponse
    {
        $record = BackupRecord::findOrFail($id);

        try {
            if ($record->file_path) {
                $disk = config('vanguard.destinations.local.disk', 'local');
                \Illuminate\Support\Facades\Storage::disk($disk)->delete($record->file_path);
            }
            if ($record->remote_path) {
                $disk = config('vanguard.destinations.remote.disk', 's3');
                \Illuminate\Support\Facades\Storage::disk($disk)->delete($record->remote_path);
            }
            $record->delete();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Backup deleted successfully.']);
    }

    // POST /vanguard/api/backups/{id}/restore
    public function restore(int $id, Request $request, RestoreService $restoreService): JsonResponse
    {
        $record = BackupRecord::findOrFail($id);

        try {
            $restoreService->restore($record, [
                'verify_checksum' => $request->boolean('verify_checksum', true),
                'restore_db'      => $request->boolean('restore_db', true),
                'restore_storage' => $request->boolean('restore_storage', false),
            ]);
            return response()->json(['message' => 'Restore completed successfully.']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function formatRecord(BackupRecord $r): array
    {
        return [
            'id'            => $r->id,
            'tenant_id'     => $r->tenant_id,
            'type'          => $r->type,
            'status'        => $r->status,
            'file_size'     => $r->file_size,
            'file_size_human' => $r->file_size_human,
            'duration'      => $r->duration,
            'checksum'      => $r->checksum,
            'destinations'  => $r->destinations,
            'error'         => $r->error,
            'started_at'    => $r->started_at?->toIso8601String(),
            'completed_at'  => $r->completed_at?->toIso8601String(),
            'created_at'    => $r->created_at->toIso8601String(),
        ];
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit  = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }
        return round($bytes, 2).' '.$units[$unit];
    }
}
