<?php

namespace SoftArtisan\Vanguard\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

class BackupRecord extends Model
{
    use Prunable;

    protected $table = 'vanguard_backups';

    protected $fillable = [
        'tenant_id',
        'type',       // 'landlord' | 'tenant' | 'filesystem'
        'status',     // 'pending' | 'running' | 'completed' | 'failed'
        'sources',    // JSON: which sources were backed up
        'destinations',
        'file_path',
        'remote_path',
        'file_size',
        'checksum',
        'error',
        'meta',
        'started_at',
        'completed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sources'      => 'array',
        'destinations' => 'array',
        'meta'         => 'array',
        'file_size'    => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Scopes ───────────────────────────────────────────────

    /**
     * Scope to backups belonging to a specific tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to landlord (non-tenant) backups only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLandlord($query)
    {
        return $query->whereNull('tenant_id');
    }

    /**
     * Scope to successfully completed backups.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to backups currently in progress.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to failed backups.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // ─── Accessors ────────────────────────────────────────────

    /**
     * Get the human-readable file size (e.g. "4.2 MB").
     *
     * Returns '—' when no file size is recorded.
     *
     * @return string
     */
    public function getFileSizeHumanAttribute(): string
    {
        if (! $this->file_size) return '—';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->file_size;
        $unit  = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2).' '.$units[$unit];
    }

    /**
     * Get a human-readable duration string (e.g. "1m 23s").
     *
     * Returns null when start or end timestamps are not available.
     *
     * @return string|null
     */
    public function getDurationAttribute(): ?string
    {
        if (! $this->started_at || ! $this->completed_at) return null;
        $seconds = abs($this->completed_at->diffInSeconds($this->started_at));
        if ($seconds < 60) return "{$seconds}s";
        $minutes = floor($seconds / 60);
        $secs    = $seconds % 60;
        return "{$minutes}m {$secs}s";
    }

    // ─── Status helpers ───────────────────────────────────────

    /** @return bool Whether the backup completed successfully. */
    public function isCompleted(): bool { return $this->status === 'completed'; }

    /** @return bool Whether the backup is currently running. */
    public function isRunning(): bool   { return $this->status === 'running'; }

    /** @return bool Whether the backup failed. */
    public function isFailed(): bool    { return $this->status === 'failed'; }

    /** @return bool Whether the backup is queued and not yet started. */
    public function isPending(): bool   { return $this->status === 'pending'; }

    // ─── Prunable ─────────────────────────────────────────────

    /**
     * Define the prunable query for automatic cleanup via Laravel's model pruning.
     *
     * Records older than the configured retention period (vanguard.retention.days)
     * and with a 'completed' status are eligible for pruning.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function prunable()
    {
        $days = config('vanguard.retention.days', 30);
        return static::where('created_at', '<=', now()->subDays($days))
                     ->where('status', 'completed');
    }

    /**
     * Delete associated backup files from disk before the model record is removed.
     *
     * Called automatically by Laravel's Prunable trait just before each record
     * is deleted (via `php artisan model:prune`). Failures are logged as warnings
     * and do not block pruning so that one unreadable disk never halts the entire run.
     */
    protected function pruning(): void
    {
        try {
            if ($this->file_path && config('vanguard.destinations.local.enabled', true)) {
                Storage::disk(config('vanguard.destinations.local.disk', 'local'))
                    ->delete($this->file_path);
            }

            if ($this->remote_path && config('vanguard.destinations.remote.enabled', false)) {
                Storage::disk(config('vanguard.destinations.remote.disk', 's3'))
                    ->delete($this->remote_path);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[Vanguard] Could not delete file during model pruning for record #{$this->id}: ".$e->getMessage()
            );
        }
    }
}
