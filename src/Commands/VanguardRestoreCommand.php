<?php

namespace SoftArtisan\Vanguard\Commands;

use Illuminate\Console\Command;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\RestoreService;

class VanguardRestoreCommand extends Command
{
    protected $signature = 'vanguard:restore
                            {id : The backup record ID to restore}
                            {--no-verify : Skip checksum verification}
                            {--no-db : Skip database restore}
                            {--restore-storage : Also restore filesystem (dangerous)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Restore a backup by its record ID';

    public function handle(RestoreService $restoreService): int
    {
        $record = BackupRecord::find($this->argument('id'));

        if (! $record) {
            $this->error("Backup record [{$this->argument('id')}] not found.");
            return self::FAILURE;
        }

        $this->warn('⚠️  You are about to restore a backup. This will overwrite existing data.');
        $this->table(['Field', 'Value'], [
            ['ID',       $record->id],
            ['Type',     $record->type],
            ['Tenant',   $record->tenant_id ?? 'landlord'],
            ['Created',  $record->created_at->toDateTimeString()],
            ['Size',     $record->file_size_human],
            ['Status',   $record->status],
        ]);

        if (! $this->option('force') && ! $this->confirm('Do you want to proceed?')) {
            $this->info('Restore cancelled.');
            return self::SUCCESS;
        }

        try {
            $restoreService->restore($record, [
                'verify_checksum' => ! $this->option('no-verify'),
                'restore_db'      => ! $this->option('no-db'),
                'restore_storage' => $this->option('restore-storage'),
            ]);

            $this->info('✅ Restore completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('✗ Restore failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
