<?php

namespace SoftArtisan\Vanguard\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

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

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLandlord($query)
    {
        return $query->whereNull('tenant_id');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // ─── Accessors ────────────────────────────────────────────

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

    public function getDurationAttribute(): ?string
    {
        if (! $this->started_at || ! $this->completed_at) return null;
        $seconds = abs($this->completed_at->diffInSeconds($this->started_at));
        if ($seconds < 60) return "{$seconds}s";
        $minutes = floor($seconds / 60);
        $secs    = $seconds % 60;
        return "{$minutes}m {$secs}s";
    }

    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isRunning(): bool   { return $this->status === 'running'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }
    public function isPending(): bool   { return $this->status === 'pending'; }

    // ─── Prunable ─────────────────────────────────────────────

    public function prunable()
    {
        $days = config('vanguard.retention.days', 30);
        return static::where('created_at', '<=', now()->subDays($days))
                     ->where('status', 'completed');
    }
}
