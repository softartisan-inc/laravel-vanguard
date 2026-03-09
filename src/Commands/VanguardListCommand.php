<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Models\BackupRecord;

class VanguardListCommand extends Command
{
    protected $signature = 'vanguard:list
                            {--tenant= : Filter by tenant ID}
                            {--status= : Filter by status (completed|failed|running)}
                            {--limit=20 : Number of records to show}';

    protected $description = 'List Vanguard backup records';

    public function handle(): int
    {
        $query = BackupRecord::latest();

        if ($tenant = $this->option('tenant')) {
            $query->forTenant($tenant);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $records = $query->limit((int) $this->option('limit'))->get();

        if ($records->isEmpty()) {
            $this->info('No backup records found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Type', 'Tenant', 'Status', 'Size', 'Duration', 'Created At'],
            $records->map(fn ($r) => [
                $r->id,
                $r->type,
                $r->tenant_id ?? 'landlord',
                $this->colorStatus($r->status),
                $r->file_size_human,
                $r->duration ?? '—',
                $r->created_at->toDateTimeString(),
            ])->toArray(),
        );

        return self::SUCCESS;
    }

    protected function colorStatus(string $status): string
    {
        return match ($status) {
            'completed' => "<fg=green>{$status}</>",
            'failed'    => "<fg=red>{$status}</>",
            'running'   => "<fg=yellow>{$status}</>",
            default     => $status,
        };
    }
}
