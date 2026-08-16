<?php

namespace SoftArtisan\Vanguard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'restore_db',
        'restore_storage',
        'verify_checksum',
        'status',
        'phase',
        'error',
        'requested_by',
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
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markPhase(string $phase): void
    {
        $this->update(['phase' => $phase]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed', 'phase' => null, 'completed_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
    }
}
