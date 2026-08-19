<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class RestoresEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
    }

    #[Test]
    public function it_lists_the_history_newest_first_with_pagination_meta(): void
    {
        $this->makeRestore(['status' => 'completed', 'created_at' => now()->subHours(2)]);
        $latest = $this->makeRestore(['status' => 'failed', 'created_at' => now()]);

        $response = $this->getJson('/vanguard/api/restores')->assertOk();

        $response->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    #[Test]
    public function it_filters_by_status_and_by_tenant(): void
    {
        $this->makeRestore(['status' => 'completed', 'tenant_id' => '9001', 'type' => 'tenant']);
        $this->makeRestore(['status' => 'failed', 'tenant_id' => '9001', 'type' => 'tenant']);
        $this->makeRestore(['status' => 'failed', 'tenant_id' => '9002', 'type' => 'tenant']);

        $this->getJson('/vanguard/api/restores?status=failed')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/vanguard/api/restores?tenant_id=9001')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/vanguard/api/restores?status=failed&tenant_id=9002')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_rejects_an_unknown_status_filter(): void
    {
        $this->getJson('/vanguard/api/restores?status=nearly')->assertStatus(422);
    }

    #[Test]
    public function a_single_restore_shows_its_phase_while_it_runs(): void
    {
        // This endpoint is the fallback the dashboard polls every two seconds
        // when a proxy cuts the SSE stream, so a running restore has to be
        // readable one row at a time.
        $restore = $this->makeRestore([
            'status' => 'running',
            'phase' => 'restoring database',
            'tenant_id' => '9001',
            'type' => 'tenant',
        ]);

        $this->getJson("/vanguard/api/restores/{$restore->id}")->assertOk()
            ->assertJsonPath('data.id', $restore->id)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.phase', 'restoring database')
            ->assertJsonPath('data.target', '9001')
            ->assertJsonPath('data.requested_by', 'tester');
    }

    #[Test]
    public function it_returns_the_exact_error_rather_than_a_log_reference(): void
    {
        // "Check server logs for details" is what made a failed restore
        // unfixable from the dashboard. The operator reading this screen is
        // the person who has to act on it.
        $restore = $this->makeRestore([
            'status' => 'failed',
            'error' => "mysql: unknown option '--single-transaction'",
        ]);

        $this->getJson("/vanguard/api/restores/{$restore->id}")->assertOk()
            ->assertJsonPath('data.error', "mysql: unknown option '--single-transaction'");
    }

    #[Test]
    public function it_answers_404_for_a_restore_that_does_not_exist(): void
    {
        $this->getJson('/vanguard/api/restores/4242')->assertStatus(404);
    }

    #[Test]
    public function the_history_is_readable_after_its_backup_is_gone(): void
    {
        $backup = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001']);
        $restore = $this->makeRestore([
            'backup_id' => $backup->id,
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'completed',
        ]);

        $backup->delete();

        $this->getJson("/vanguard/api/restores/{$restore->id}")->assertOk()
            ->assertJsonPath('data.backup_id', null)
            ->assertJsonPath('data.target', '9001');
    }

    #[Test]
    public function a_rehearsal_is_told_apart_from_a_restore_of_the_target(): void
    {
        $rehearsal = $this->makeRestore([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'completed',
            'target_database' => 'vanguard_rehearsal',
        ]);

        $real = $this->makeRestore([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'completed',
        ]);

        // Two completed restores of the same tenant, one of which never
        // touched that tenant's data. Reading the same on screen is what
        // makes a history untrustworthy.
        $this->getJson("/vanguard/api/restores/{$rehearsal->id}")->assertOk()
            ->assertJsonPath('data.target_database', 'vanguard_rehearsal');

        $this->getJson("/vanguard/api/restores/{$real->id}")->assertOk()
            ->assertJsonPath('data.target_database', null);
    }

    #[Test]
    public function it_says_which_path_asked_for_the_restore(): void
    {
        $fromApi = $this->makeRestore(['status' => 'completed', 'origin' => 'api']);
        $fromConsole = $this->makeRestore(['status' => 'completed', 'origin' => 'console']);
        $unknown = $this->makeRestore(['status' => 'completed']);

        $this->getJson("/vanguard/api/restores/{$fromApi->id}")->assertOk()
            ->assertJsonPath('data.origin', 'api');

        $this->getJson("/vanguard/api/restores/{$fromConsole->id}")->assertOk()
            ->assertJsonPath('data.origin', 'console');

        // Rows written before the column existed came from a path nobody
        // recorded. Null says so; guessing 'api' would invent history.
        $this->getJson("/vanguard/api/restores/{$unknown->id}")->assertOk()
            ->assertJsonPath('data.origin', null);
    }
}
