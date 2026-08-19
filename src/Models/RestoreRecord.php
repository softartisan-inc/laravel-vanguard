<?php

namespace SoftArtisan\Vanguard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * One restore, as it happened.
 *
 * The target fields are copied from the backup rather than read through the
 * relation: a backup can be pruned or deleted, and an operational history that
 * disappears with it answers nothing.
 */
class RestoreRecord extends Model
{
    protected $table = 'vanguard_restores';

    protected $fillable = [
        'backup_id',
        'type',
        'tenant_id',
        'backup_created_at',
        'source',
        'target_database',
        'restore_db',
        'restore_storage',
        'verify_checksum',
        'status',
        'phase',
        'error',
        'requested_by',
        'origin',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'restore_db' => 'boolean',
        'restore_storage' => 'boolean',
        'verify_checksum' => 'boolean',
        'backup_created_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(BackupRecord::class, 'backup_id');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ─── Status ───────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markRunning(): void
    {
        $this->forceUpdate(['status' => 'running', 'started_at' => now()]);
    }

    public function markPhase(string $phase): void
    {
        $this->forceUpdate(['phase' => $phase]);
    }

    public function markCompleted(): void
    {
        $this->forceUpdate(['status' => 'completed', 'phase' => null, 'completed_at' => now()]);
    }

    /**
     * @param  array  $extra  Additional columns to write alongside the
     *                        failure, e.g. ['started_at' => now()] for a row
     *                        that failed before it ever ran. forceUpdate()
     *                        only writes the keys it is given, so a caller
     *                        that also wants started_at set must pass it here
     *                        rather than fill()ing it beforehand.
     */
    public function markFailed(string $error, array $extra = []): void
    {
        // Truncated: 'error' is a MySQL text column (65,535 bytes), and
        // DatabaseDriver builds messages from captured stderr — a bad dump
        // replayed through psql routinely exceeds that. Under strict mode an
        // unbounded value throws here, which would suppress the alert that
        // follows this call.
        $this->forceUpdate(array_merge($extra, [
            'status' => 'failed',
            'error' => Str::limit($error, 60000),
            'completed_at' => now(),
        ]));
    }

    /**
     * Write straight to the row by primary key, bypassing Eloquent's
     * dirty-attribute tracking.
     *
     * A restore's phase can be written by a raw query on a pinned connection
     * while the tenancy layer has the default connection swapped (see
     * RunRestoreJob::handle()), which never touches this instance's
     * in-memory attributes. save()'s normal dirty-check would then compare
     * a field like 'phase' against a stale in-memory value, see no change,
     * and silently drop it from the UPDATE — e.g. markCompleted() resetting
     * phase to null would no-op because the instance never knew a raw write
     * had already moved it away from null. Every "mark" transition writes
     * the fields it names unconditionally instead.
     */
    protected function forceUpdate(array $attributes): void
    {
        $attributes['updated_at'] = now();

        // forceFill() runs every value through setAttribute(), which
        // stringifies the datetime casts (started_at, completed_at,
        // updated_at) exactly the way save() would. The raw query below
        // needs those normalized values, not raw Carbon instances — it
        // talks to the query builder directly rather than through save().
        $this->forceFill($attributes);

        $this->newQueryWithoutScopes()
            ->where($this->getKeyName(), $this->getKey())
            ->update(Arr::only($this->getAttributes(), array_keys($attributes)));

        $this->syncOriginal();
    }
}
