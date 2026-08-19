<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Http\Controllers\OperationsApiController;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The screen an operator leaves open during an incident.
 *
 * Everything asserted here is a judgement the payload makes rather than a
 * number it copies: what is running and for how long, what is waiting behind
 * it, and whether anything is consuming the queue at all.
 */
class OperationsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
    }

    #[Test]
    public function an_idle_installation_says_so_without_alarming(): void
    {
        $response = $this->getJson('/vanguard/api/operations')->assertOk();

        $this->assertSame([], $response->json('running.backups'));
        $this->assertSame([], $response->json('running.restores'));
        $this->assertSame([], $response->json('waiting.restores'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertNotNull($response->json('generated_at'));
    }

    #[Test]
    public function a_running_backup_carries_how_long_it_has_been_running(): void
    {
        $backup = $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subMinutes(3),
            'completed_at' => null,
        ]);

        $row = $this->getJson('/vanguard/api/operations')->assertOk()->json('running.backups.0');

        $this->assertSame($backup->id, $row['id']);
        $this->assertSame('landlord', $row['target']);
        $this->assertGreaterThanOrEqual(180, $row['elapsed_seconds']);
    }

    #[Test]
    public function a_running_restore_carries_its_live_phase(): void
    {
        $restore = $this->makeRestore([
            'status' => 'running',
            'phase' => 'importing_database',
            'started_at' => now()->subSeconds(30),
        ]);

        $row = $this->getJson('/vanguard/api/operations')->assertOk()->json('running.restores.0');

        $this->assertSame($restore->id, $row['id']);
        $this->assertSame('importing_database', $row['phase']);
        $this->assertSame('tester', $row['requested_by']);
    }

    #[Test]
    public function a_restore_waiting_with_nothing_consuming_the_queue_is_named_as_such(): void
    {
        $restore = $this->makeRestore(['status' => 'pending']);
        // Written past the model's guard: `created_at` is not fillable, and
        // the age of the row is the whole subject of this test.
        $restore->forceFill(['created_at' => now()->subMinutes(6)])->save();

        $response = $this->getJson('/vanguard/api/operations')->assertOk();

        $this->assertSame($restore->id, $response->json('waiting.restores.0.id'));

        $warning = collect($response->json('warnings'))->firstWhere('code', 'no_worker');

        $this->assertNotNull($warning, 'a restore pending for six minutes with no worker has to be said out loud');
        $this->assertSame('danger', $warning['level']);
        $this->assertStringContainsString('queue:work', $warning['message']);
        $this->assertSame('restore', $warning['rows'][0]['kind']);
        $this->assertGreaterThanOrEqual(360, $warning['rows'][0]['waiting_seconds']);
    }

    #[Test]
    public function a_job_just_dispatched_is_not_called_stuck(): void
    {
        $this->makeRestore(['status' => 'pending', 'created_at' => now()]);

        $warnings = collect($this->getJson('/vanguard/api/operations')->assertOk()->json('warnings'));

        $this->assertNull($warnings->firstWhere('code', 'no_worker'));
    }

    #[Test]
    public function a_queue_that_cannot_be_read_is_unknown_rather_than_empty(): void
    {
        config(['vanguard.queue.connection' => 'a-connection-that-does-not-exist']);

        $response = $this->getJson('/vanguard/api/operations')->assertOk();

        $this->assertNull($response->json('queue.pending'));
        $this->assertNotNull($response->json('queue.reason'));

        $warning = collect($response->json('warnings'))->firstWhere('code', 'queue_unreadable');

        $this->assertNotNull($warning);
        $this->assertSame('danger', $warning['level']);
    }

    #[Test]
    public function a_run_past_the_queue_timeout_is_flagged_as_stalled(): void
    {
        config(['vanguard.queue.timeout' => 600]);

        $this->makeRecord([
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'completed_at' => null,
        ]);

        $warning = collect($this->getJson('/vanguard/api/operations')->assertOk()->json('warnings'))
            ->firstWhere('code', 'stalled');

        $this->assertNotNull($warning, 'a worker killed mid-backup leaves a row running for ever, and only this says so');
        $this->assertStringContainsString('600', $warning['message']);
    }

    #[Test]
    public function recent_failures_carry_their_exact_error(): void
    {
        $this->makeRecord([
            'status' => 'failed',
            'error' => 'mysqldump: Got error: 1045: Access denied',
            'completed_at' => null,
        ]);

        // Older than a day: history, not the incident in progress.
        $this->makeRecord([
            'status' => 'failed',
            'error' => 'last week',
            'created_at' => now()->subDays(3),
        ]);

        $failures = $this->getJson('/vanguard/api/operations')->assertOk()->json('recent_failures.backups');

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('Access denied', $failures[0]['error']);
    }

    #[Test]
    public function the_payload_is_bounded(): void
    {
        // A thousand running rows is itself the incident; the screen stays a
        // screen rather than becoming a dump.
        $this->assertSame(50, OperationsApiController::MAX_ROWS);
    }

    #[Test]
    public function a_running_rehearsal_is_not_shown_as_a_restore_of_the_target(): void
    {
        $this->makeRestore([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'running',
            'target_database' => 'vanguard_rehearsal',
            'started_at' => now()->subMinute(),
        ]);

        // "Restoring tenant 9001" on screen, for a run writing to a throwaway
        // database, is a line that starts an incident that is not happening.
        $this->getJson('/vanguard/api/operations')->assertOk()
            ->assertJsonPath('running.restores.0.target_database', 'vanguard_rehearsal');
    }
}
