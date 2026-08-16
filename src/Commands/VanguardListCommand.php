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

    /**
     * Execute the console command.
     *
     * Queries backup records with optional filters and renders them as a table.
     *
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $query = BackupRecord::latest();

        if ($tenant = $this->option('tenant')) {
            $query->forTenant($tenant);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $records = $query->limit($this->resolveLimit())->get();

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

    /**
     * How many records to show, held between 1 and 1000.
     *
     * The option went into the query as a raw cast, so a typo read as 0 and
     * the command answered "No backup records found." on an installation with
     * hundreds of them — the most alarming possible lie from a command whose
     * whole job is to say whether backups exist. Clamping is safe here in a
     * way it would never be on vanguard:prune: this command only reads, so a
     * coerced value shows the wrong number of rows rather than deleting the
     * wrong number of archives.
     *
     * @return int A limit in [1, 1000]
     */
    protected function resolveLimit(): int
    {
        $given = $this->option('limit');

        return max(1, min(1000, (int) ($given ?? 20)));
    }

    /**
     * Wrap a status string in an Artisan console color tag.
     *
     * @param  string  $status  'completed'|'failed'|'running'|other
     * @return string The status string wrapped in a color tag for terminal output
     */
    protected function colorStatus(string $status): string
    {
        return match ($status) {
            'completed' => "<fg=green>{$status}</>",
            'failed' => "<fg=red>{$status}</>",
            'running' => "<fg=yellow>{$status}</>",
            default => $status,
        };
    }
}
