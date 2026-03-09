<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Models;

use Carbon\Carbon;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;

class BackupRecordTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Status helpers
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_correctly_identifies_completed_status(): void
    {
        $record = $this->makeRecord(['status' => 'completed']);

        $this->assertTrue($record->isCompleted());
        $this->assertFalse($record->isRunning());
        $this->assertFalse($record->isFailed());
        $this->assertFalse($record->isPending());
    }

    /** @test */
    public function it_correctly_identifies_running_status(): void
    {
        $record = $this->makeRecord(['status' => 'running']);

        $this->assertTrue($record->isRunning());
        $this->assertFalse($record->isCompleted());
        $this->assertFalse($record->isFailed());
    }

    /** @test */
    public function it_correctly_identifies_failed_status(): void
    {
        $record = $this->makeRecord(['status' => 'failed']);

        $this->assertTrue($record->isFailed());
        $this->assertFalse($record->isCompleted());
        $this->assertFalse($record->isRunning());
    }

    /** @test */
    public function it_correctly_identifies_pending_status(): void
    {
        $record = $this->makeRecord(['status' => 'pending']);

        $this->assertTrue($record->isPending());
        $this->assertFalse($record->isCompleted());
    }

    // ─────────────────────────────────────────────────────────────
    // file_size_human accessor
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_formats_file_size_in_bytes(): void
    {
        $record = $this->makeRecord(['file_size' => 512]);
        $this->assertSame('512 B', $record->file_size_human);
    }

    /** @test */
    public function it_formats_file_size_in_kilobytes(): void
    {
        $record = $this->makeRecord(['file_size' => 2048]);
        $this->assertSame('2 KB', $record->file_size_human);
    }

    /** @test */
    public function it_formats_file_size_in_megabytes(): void
    {
        $record = $this->makeRecord(['file_size' => 1024 * 1024 * 5]);
        $this->assertSame('5 MB', $record->file_size_human);
    }

    /** @test */
    public function it_formats_file_size_in_gigabytes(): void
    {
        $record = $this->makeRecord(['file_size' => 1024 * 1024 * 1024 * 2]);
        $this->assertSame('2 GB', $record->file_size_human);
    }

    /** @test */
    public function it_returns_dash_when_file_size_is_null(): void
    {
        $record = $this->makeRecord(['file_size' => null]);
        $this->assertSame('—', $record->file_size_human);
    }

    // ─────────────────────────────────────────────────────────────
    // duration accessor
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_returns_duration_in_seconds_when_under_one_minute(): void
    {
        $record = $this->makeRecord([
            'started_at'   => Carbon::now()->subSeconds(45),
            'completed_at' => Carbon::now(),
        ]);

        $this->assertSame('45s', $record->duration);
    }

    /** @test */
    public function it_returns_duration_in_minutes_and_seconds(): void
    {
        $record = $this->makeRecord([
            'started_at'   => Carbon::now()->subSeconds(125),
            'completed_at' => Carbon::now(),
        ]);

        $this->assertSame('2m 5s', $record->duration);
    }

    /** @test */
    public function it_returns_null_duration_when_dates_are_missing(): void
    {
        $record = $this->makeRecord([
            'started_at'   => null,
            'completed_at' => null,
        ]);

        $this->assertNull($record->duration);
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function scope_completed_filters_correctly(): void
    {
        $this->makeRecord(['status' => 'completed']);
        $this->makeRecord(['status' => 'completed']);
        $this->makeRecord(['status' => 'failed']);
        $this->makeRecord(['status' => 'running']);

        $this->assertCount(2, BackupRecord::completed()->get());
    }

    /** @test */
    public function scope_running_filters_correctly(): void
    {
        $this->makeRecord(['status' => 'running']);
        $this->makeRecord(['status' => 'completed']);

        $this->assertCount(1, BackupRecord::running()->get());
    }

    /** @test */
    public function scope_failed_filters_correctly(): void
    {
        $this->makeRecord(['status' => 'failed']);
        $this->makeRecord(['status' => 'failed']);
        $this->makeRecord(['status' => 'completed']);

        $this->assertCount(2, BackupRecord::failed()->get());
    }

    /** @test */
    public function scope_landlord_filters_correctly(): void
    {
        $this->makeRecord(['tenant_id' => null]);
        $this->makeRecord(['tenant_id' => null]);
        $this->makeRecord(['tenant_id' => 'acme']);

        $this->assertCount(2, BackupRecord::landlord()->get());
    }

    /** @test */
    public function scope_for_tenant_filters_by_tenant_id(): void
    {
        $this->makeRecord(['tenant_id' => 'acme']);
        $this->makeRecord(['tenant_id' => 'acme']);
        $this->makeRecord(['tenant_id' => 'globex']);
        $this->makeRecord(['tenant_id' => null]);

        $this->assertCount(2, BackupRecord::forTenant('acme')->get());
        $this->assertCount(1, BackupRecord::forTenant('globex')->get());
    }

    // ─────────────────────────────────────────────────────────────
    // JSON casts
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function it_casts_sources_and_destinations_as_arrays(): void
    {
        $record = $this->makeRecord([
            'sources'      => ['landlord_database', 'filesystem'],
            'destinations' => ['local', 'remote'],
        ]);

        $fresh = $record->fresh();

        $this->assertIsArray($fresh->sources);
        $this->assertIsArray($fresh->destinations);
        $this->assertContains('landlord_database', $fresh->sources);
        $this->assertContains('remote', $fresh->destinations);
    }

    /** @test */
    public function it_casts_meta_as_array(): void
    {
        $record = $this->makeRecord(['meta' => ['include_filesystem' => true, 'tag' => 'nightly']]);

        $fresh = $record->fresh();

        $this->assertIsArray($fresh->meta);
        $this->assertTrue($fresh->meta['include_filesystem']);
        $this->assertSame('nightly', $fresh->meta['tag']);
    }

    // ─────────────────────────────────────────────────────────────
    // Prunable
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function prunable_only_includes_completed_records_beyond_retention(): void
    {
        config(['vanguard.retention.days' => 7]);

        // Should be pruned: completed + old
        $old = $this->makeRecord(['status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subDays(10)]);

        // Should NOT be pruned: recent
        $recent = $this->makeRecord(['status' => 'completed']);

        // Should NOT be pruned: failed (even if old)
        $failed = $this->makeRecord(['status' => 'failed']);
        \Illuminate\Support\Facades\DB::table('vanguard_backups')
            ->where('id', $failed->id)
            ->update(['created_at' => now()->subDays(10)]);

        $prunable = (new BackupRecord)->prunable()->get();

        $this->assertCount(1, $prunable);
        $this->assertSame('completed', $prunable->first()->status);
        $this->assertSame($old->id, $prunable->first()->id);
    }
}
