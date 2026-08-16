<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Vanguard;

class RestoresApiController extends Controller
{
    /**
     * GET /vanguard/api/restores
     *
     * The restore history, newest first, filterable by status and tenant.
     * Never pruned: these rows weigh a few bytes and answer "who restored
     * what, when" long after the archive itself is gone.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,running,completed,failed',
            'tenant_id' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = RestoreRecord::on(Vanguard::centralConnection())->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($tenantId = $request->get('tenant_id')) {
            $query->forTenant($tenantId);
        }

        $records = $query->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'data' => collect($records->items())->map(fn ($r) => $this->format($r)),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * GET /vanguard/api/restores/{id}
     *
     * One row. This is the fallback the dashboard polls every two seconds
     * when a proxy cuts the SSE stream, so it has to carry the live phase.
     */
    public function show(int $id): JsonResponse
    {
        $record = RestoreRecord::on(Vanguard::centralConnection())->find($id);

        if ($record === null) {
            return response()->json(['error' => "Restore #{$id} not found."], 404);
        }

        return response()->json(['data' => $this->format($record)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function format(RestoreRecord $r): array
    {
        return [
            'id' => $r->id,
            'backup_id' => $r->backup_id,
            'type' => $r->type,
            'tenant_id' => $r->tenant_id,
            // The name the operator types to confirm, precomputed so the
            // interface and the API agree on what the target is called.
            'target' => $r->tenant_id ?? $r->type,
            'backup_created_at' => $r->backup_created_at?->toIso8601String(),
            'source' => $r->source,
            'restore_db' => $r->restore_db,
            'restore_storage' => $r->restore_storage,
            'verify_checksum' => $r->verify_checksum,
            'status' => $r->status,
            'phase' => $r->phase,
            // The exact message, not a redaction. "Check server logs for
            // details" is what made a failed restore unfixable from here.
            'error' => $r->error,
            'requested_by' => $r->requested_by,
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
