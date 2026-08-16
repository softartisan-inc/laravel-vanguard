<?php

namespace SoftArtisan\Vanguard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The two things every destructive Vanguard endpoint owes the operator:
 * a chance to prove they meant it, and a record that they did it.
 */
trait GuardsDestructiveActions
{
    /**
     * Refuse the request unless `confirm` repeats the target's name exactly.
     *
     * This is an API rule, not an interface courtesy: a curl call without it
     * is refused the same way the dashboard's disabled button refuses a click.
     * A --days typo really did erase seventeen backups during the 16 August
     * tests, and a restore overwrites a live database.
     *
     * @param  string  $target  The name the caller must type back: a tenant
     *                          key, 'landlord', 'all-backups', 'tmp'.
     * @return JsonResponse|null Null when confirmed, the 400 otherwise.
     */
    protected function rejectUnlessConfirmed(Request $request, string $target): ?JsonResponse
    {
        $confirm = $request->input('confirm');

        // Strictly a string, strictly identical. {"confirm": true} decodes to
        // a boolean and would survive a loose comparison against any non-empty
        // target.
        if (! is_string($confirm) || $confirm !== $target) {
            return response()->json([
                'error' => "Type the target name to confirm this operation: expected [{$target}].",
                'expected' => $target,
            ], 400);
        }

        return null;
    }

    /**
     * Record who did what, to which target.
     *
     * At warning level rather than info: an audit trail that a production
     * LOG_LEVEL silently discards is not an audit trail. Restore, prune,
     * delete and download all pass through here (spec §7) — download included,
     * because it takes every tenant's database, personal data and all, off
     * the server.
     */
    protected function trace(string $action, string $target, array $context = []): void
    {
        Log::warning('[Vanguard] '.$action, array_merge([
            'actor' => Vanguard::actor() ?? 'unknown',
            'target' => $target,
        ], $context));
    }
}
