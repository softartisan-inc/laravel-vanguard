<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Tests\TestCase;

class RestoreRecordTest extends TestCase
{
    #[Test]
    public function it_walks_a_restore_from_pending_to_completed(): void
    {
        $restore = $this->makeRestore(['status' => 'pending']);

        $restore->markRunning();
        $this->assertTrue($restore->isRunning());
        $this->assertNotNull($restore->started_at);

        $restore->markPhase('restoring database');
        $this->assertSame('restoring database', $restore->fresh()->phase);

        $restore->markCompleted();
        $this->assertSame('completed', $restore->fresh()->status);
        $this->assertNotNull($restore->fresh()->completed_at);
    }

    #[Test]
    public function it_keeps_the_exact_error_of_a_failed_restore(): void
    {
        $restore = $this->makeRestore(['status' => 'running']);

        $restore->markFailed('mysql: unknown option \'--single-transaction\'');

        $this->assertTrue($restore->fresh()->isFailed());
        $this->assertStringContainsString('unknown option', $restore->fresh()->error);
        $this->assertNotNull($restore->fresh()->completed_at);
    }

    #[Test]
    public function the_history_survives_the_deletion_of_its_backup(): void
    {
        // A history whose rows depend on a deletable record is not a history.
        // SQLite enforces foreign keys only when asked. The connection config sets
        // 'foreign_key_constraints' => true, which ensures the pragma is applied
        // at connection time (before any transaction), so the nullOnDelete constraint
        // is enforced during this test.

        $backup = $this->makeRecord();
        $restore = $this->makeRestore([
            'backup_id' => $backup->id,
            'type' => 'tenant',
            'tenant_id' => '9001',
        ]);

        $backup->delete();

        $restore = $restore->fresh();

        $this->assertNotNull($restore, 'the restore row must outlive the backup');
        $this->assertNull($restore->backup_id);
        $this->assertSame('tenant', $restore->type, 'the target is copied, not looked up');
        $this->assertSame('9001', $restore->tenant_id);
    }

    #[Test]
    public function it_filters_by_status_and_tenant(): void
    {
        $this->makeRestore(['status' => 'completed', 'tenant_id' => '9001']);
        $this->makeRestore(['status' => 'failed', 'tenant_id' => '9001']);
        $this->makeRestore(['status' => 'failed', 'tenant_id' => '9002']);

        $this->assertCount(2, RestoreRecord::failed()->get());
        $this->assertCount(2, RestoreRecord::forTenant('9001')->get());
        $this->assertCount(1, RestoreRecord::failed()->forTenant('9002')->get());
    }
}
