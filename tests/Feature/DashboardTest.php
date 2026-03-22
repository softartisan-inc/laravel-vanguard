<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mockery;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Services\BackupManager;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Allow all requests through the auth middleware for tests
        Vanguard::auth(fn ($request) => true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Auth middleware
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function dashboard_returns_403_when_auth_gate_denies(): void
    {
        Vanguard::auth(fn ($request) => false);

        $this->get('/vanguard')->assertStatus(403);
    }

    /** @test */
    public function dashboard_is_accessible_when_auth_gate_passes(): void
    {
        $this->get('/vanguard')->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────
    // GET /vanguard/api/stats
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function stats_endpoint_returns_correct_structure(): void
    {
        $this->makeRecord(['status' => 'completed', 'file_size' => 1024 * 500]);
        $this->makeRecord(['status' => 'running']);
        $this->makeRecord(['status' => 'failed']);

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(false);
        $tenancy->shouldReceive('allTenants')->andReturn(collect());
        $this->app->instance(TenancyResolver::class, $tenancy);

        $response = $this->getJson('/vanguard/api/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'total_tenants',
                'total_backups',
                'running_backups',
                'failed_recent',
                'total_size_bytes',
                'total_size_human',
                'recent_backups',
            ]);

        $data = $response->json();
        $this->assertSame(3, $data['total_backups']);
        $this->assertSame(1, $data['running_backups']);
    }

    /** @test */
    public function stats_endpoint_counts_tenants_when_tenancy_enabled(): void
    {
        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn(collect([
            (object) ['id' => 'acme'],
            (object) ['id' => 'globex'],
        ]));
        $this->app->instance(TenancyResolver::class, $tenancy);

        $response = $this->getJson('/vanguard/api/stats');

        $response->assertOk();
        $this->assertSame(2, $response->json('total_tenants'));
    }

    // ─────────────────────────────────────────────────────────────
    // GET /vanguard/api/backups
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function backups_endpoint_returns_paginated_records(): void
    {
        $this->makeRecord(['status' => 'completed', 'type' => 'landlord']);
        $this->makeRecord(['status' => 'failed',    'type' => 'tenant', 'tenant_id' => 'acme']);

        $response = $this->getJson('/vanguard/api/backups');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'status', 'tenant_id', 'file_size_human', 'created_at'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function backups_endpoint_filters_by_tenant_id(): void
    {
        $this->makeRecord(['tenant_id' => 'acme']);
        $this->makeRecord(['tenant_id' => 'globex']);
        $this->makeRecord(['tenant_id' => 'acme']);

        $response = $this->getJson('/vanguard/api/backups?tenant_id=acme');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function backups_endpoint_filters_by_status(): void
    {
        $this->makeRecord(['status' => 'completed']);
        $this->makeRecord(['status' => 'failed']);
        $this->makeRecord(['status' => 'failed']);

        $response = $this->getJson('/vanguard/api/backups?status=failed');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    // ─────────────────────────────────────────────────────────────
    // GET /vanguard/api/tenants
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function tenants_endpoint_returns_empty_when_tenancy_disabled(): void
    {
        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(false);
        $this->app->instance(TenancyResolver::class, $tenancy);

        $response = $this->getJson('/vanguard/api/tenants');

        $response->assertOk();
        $this->assertEmpty($response->json('tenants'));
    }

    /** @test */
    public function tenants_endpoint_returns_tenant_list_with_backup_counts(): void
    {
        $this->makeRecord(['tenant_id' => 'acme', 'status' => 'completed']);
        $this->makeRecord(['tenant_id' => 'acme', 'status' => 'completed']);

        $mockTenant = Mockery::mock();
        $mockTenant->shouldReceive('getTenantKey')->andReturn('acme');
        $mockTenant->vanguard_schedule = null;

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn(collect([$mockTenant]));
        $tenancy->shouldReceive('tenantSchedule')->andReturn(null);

        $this->app->instance(TenancyResolver::class, $tenancy);

        $response = $this->getJson('/vanguard/api/tenants');

        $response->assertOk();
        $tenants = $response->json('tenants');

        $this->assertCount(1, $tenants);
        $this->assertSame('acme', $tenants[0]['id']);
        $this->assertSame(2, $tenants[0]['total_backups']);
        $this->assertNotNull($tenants[0]['latest_backup']);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /vanguard/api/backups/run
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function run_endpoint_validates_required_type(): void
    {
        $this->postJson('/vanguard/api/backups/run', [])
            ->assertStatus(422);
    }

    /** @test */
    public function run_endpoint_requires_tenant_id_when_type_is_tenant(): void
    {
        $this->postJson('/vanguard/api/backups/run', ['type' => 'tenant'])
            ->assertStatus(422);
    }

    /** @test */
    public function run_endpoint_triggers_landlord_backup(): void
    {
        config(['vanguard.queue.enabled' => false]);

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')
            ->once()
            ->andReturn($this->makeRecord(['type' => 'landlord', 'status' => 'completed']));

        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', ['type' => 'landlord'])
            ->assertOk()
            ->assertJsonStructure(['record']);
    }

    /** @test */
    public function run_endpoint_triggers_filesystem_backup(): void
    {
        config(['vanguard.queue.enabled' => false]);

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupFilesystem')
            ->once()
            ->andReturn($this->makeRecord(['type' => 'filesystem', 'status' => 'completed']));

        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', ['type' => 'filesystem'])
            ->assertOk();
    }

    /** @test */
    public function run_endpoint_returns_500_when_backup_fails(): void
    {
        config(['vanguard.queue.enabled' => false]);

        $manager = Mockery::mock(BackupManager::class);
        $manager->shouldReceive('backupLandlord')
            ->once()
            ->andThrow(new \RuntimeException('No disk space'));

        $this->app->instance(BackupManager::class, $manager);

        $this->postJson('/vanguard/api/backups/run', ['type' => 'landlord'])
            ->assertStatus(500)
            ->assertJsonStructure(['error']);
    }

    // ─────────────────────────────────────────────────────────────
    // DELETE /vanguard/api/backups/{id}
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function destroy_endpoint_deletes_record(): void
    {
        $record = $this->makeRecord(['file_path' => null]);

        $this->deleteJson("/vanguard/api/backups/{$record->id}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertNull(BackupRecord::find($record->id));
    }

    /** @test */
    public function destroy_endpoint_returns_404_for_unknown_record(): void
    {
        $this->deleteJson('/vanguard/api/backups/99999')
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────
    // POST /vanguard/api/backups/{id}/restore
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function restore_endpoint_calls_restore_service(): void
    {
        $record = $this->makeRecord(['type' => 'landlord', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(\SoftArtisan\Vanguard\Services\RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->andReturn(true);

        $this->app->instance(\SoftArtisan\Vanguard\Services\RestoreService::class, $restoreService);

        $this->postJson("/vanguard/api/backups/{$record->id}/restore")
            ->assertOk()
            ->assertJsonStructure(['message']);
    }

    /** @test */
    public function restore_endpoint_returns_500_on_error(): void
    {
        $record = $this->makeRecord(['type' => 'landlord', 'file_path' => 'path.tar']);

        $restoreService = Mockery::mock(\SoftArtisan\Vanguard\Services\RestoreService::class);
        $restoreService->shouldReceive('restore')
            ->once()
            ->andThrow(new \RuntimeException('Checksum mismatch'));

        $this->app->instance(\SoftArtisan\Vanguard\Services\RestoreService::class, $restoreService);

        $this->postJson("/vanguard/api/backups/{$record->id}/restore")
            ->assertStatus(500)
            ->assertJson(['error' => 'Restore operation failed. Check server logs for details.']);
    }
}
