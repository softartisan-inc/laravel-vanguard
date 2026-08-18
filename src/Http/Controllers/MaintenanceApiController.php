<?php

namespace SoftArtisan\Vanguard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SoftArtisan\Vanguard\Http\Concerns\GuardsDestructiveActions;
use SoftArtisan\Vanguard\Services\BackupStorageManager;
use SoftArtisan\Vanguard\Services\StaleRunReaper;

class MaintenanceApiController extends Controller
{
    use GuardsDestructiveActions;

    public function __construct(protected BackupStorageManager $store) {}

    /**
     * POST /vanguard/api/prune
     *
     * Parity with `vanguard:prune --tenant= --days=`. Behind the same typed
     * confirmation as a restore: this deletes archives permanently, and a
     * mistyped retention erased seventeen backups during the 16 August tests.
     */
    public function prune(Request $request): JsonResponse
    {
        $request->validate([
            'days' => 'nullable|integer|min:0',
            'tenant_id' => 'nullable|string|max:255',
        ]);

        $tenantId = $request->input('tenant_id');
        $target = $tenantId ?: 'all-backups';

        if ($rejection = $this->rejectUnlessConfirmed($request, $target)) {
            return $rejection;
        }

        // Presence, not truthiness: days=0 means prune everything, and reading
        // it as falsy would silently apply the configured retention instead —
        // the opposite of what was typed.
        if ($request->has('days')) {
            config(['vanguard.retention.days' => (int) $request->input('days')]);
        }

        $days = (int) config('vanguard.retention.days', 30);

        $this->trace('prune requested', $target, [
            'days' => $days,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'deleted' => $this->store->pruneOldBackups($tenantId),
            'days' => $days,
        ]);
    }

    /**
     * POST /vanguard/api/cleanup-tmp
     *
     * Parity with `vanguard:cleanup-tmp --hours=`. Confirmed like the rest:
     * it runs `rm -rf` on server paths, and the operator should have to say
     * so out loud.
     */
    public function cleanupTmp(Request $request): JsonResponse
    {
        $request->validate(['hours' => 'nullable|integer|min:1']);

        if ($rejection = $this->rejectUnlessConfirmed($request, 'tmp')) {
            return $rejection;
        }

        $hours = (int) $request->input('hours', 6);

        $this->trace('tmp cleanup requested', 'tmp', ['hours' => $hours]);

        return response()->json([
            'removed' => $this->store->cleanOrphanedTmp($hours),
            'hours' => $hours,
            // The rows a killed worker left saying `running` are the other half
            // of the same crash, and the command sweeps both. Leaving them to
            // the CLI would make the same wording mean two different things on
            // the two surfaces.
            'reclaimed' => app(StaleRunReaper::class)->reap(),
        ]);
    }
}
