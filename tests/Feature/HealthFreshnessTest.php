<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The freshness section of the health screen.
 *
 * It is the only indicator on that page that turns red on its own when nothing
 * runs at all, and the health screen is the landing page. Both properties are
 * load-bearing: a row that cannot be trusted is worse than no row, and a page
 * whose cost grows with the customer list is a page nobody keeps open.
 */
class HealthFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
        Storage::fake('local');

        // A queue driver that answers from memory, so the only round trips
        // the count below can see are the freshness reads.
        config(['queue.default' => 'sync']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function withTenants(array $keys): void
    {
        $tenants = collect($keys)->map(fn (string $key) => new class($key)
        {
            public function __construct(public string $key) {}

            public function getTenantKey(): string
            {
                return $this->key;
            }
        });

        $tenancy = Mockery::mock(TenancyResolver::class);
        $tenancy->shouldReceive('isEnabled')->andReturn(true);
        $tenancy->shouldReceive('allTenants')->andReturn($tenants);
        $tenancy->shouldReceive('tenantSchedule')->andReturn(null);

        $this->app->instance(TenancyResolver::class, $tenancy);
    }

    protected function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    // ─── Query count ─────────────────────────────────────────────

    #[Test]
    public function it_reads_every_tenants_freshness_without_one_query_per_tenant(): void
    {
        // Commit 334e4bb removed exactly this shape from /api/tenants: the
        // cost of the screen that says whether backups work must not grow with
        // the customer list. Two hundred tenants was two hundred reads per
        // load of the landing page.
        $keys = ['9001', '9002', '9003', '9004', '9005'];

        $this->withTenants($keys);

        foreach ($keys as $key) {
            $this->makeRecord(['type' => 'tenant', 'tenant_id' => $key, 'status' => 'completed']);
        }

        $queries = $this->countQueries(fn () => $this->getJson('/vanguard/api/health')->assertOk());

        $this->assertLessThanOrEqual(
            3,
            $queries,
            "the health endpoint ran {$queries} queries for five tenants: the count is following the tenant list",
        );
    }

    #[Test]
    public function it_still_reports_each_tenants_last_success(): void
    {
        $this->withTenants(['9001', '9002']);

        $this->makeRecord([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'completed',
            'completed_at' => now()->subMinutes(5),
        ]);

        $rows = collect($this->getJson('/vanguard/api/health')->assertOk()->json('freshness.targets'));

        $first = $rows->firstWhere('tenant_id', '9001');
        $second = $rows->firstWhere('tenant_id', '9002');

        $this->assertNotNull($first['last_success_at']);
        $this->assertFalse($first['stale']);
        $this->assertLessThan(3600, $first['age_seconds']);

        // Never backed up counts as stale: it is the worse case, not the
        // unknown one.
        $this->assertNull($second['last_success_at']);
        $this->assertNull($second['age_seconds']);
        $this->assertTrue($second['stale']);
    }

    #[Test]
    public function a_failed_tenant_backup_does_not_count_as_a_success(): void
    {
        $this->withTenants(['9001']);

        $this->makeRecord([
            'type' => 'tenant',
            'tenant_id' => '9001',
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        $row = collect($this->getJson('/vanguard/api/health')->assertOk()->json('freshness.targets'))
            ->firstWhere('tenant_id', '9001');

        $this->assertNull($row['last_success_at']);
        $this->assertTrue($row['stale']);
    }

    // ─── Landlord row ────────────────────────────────────────────

    #[Test]
    public function a_filesystem_backup_does_not_make_the_landlord_row_green(): void
    {
        // Both rows carry a null tenant_id, so selecting the landlord row on
        // that alone let a manually triggered filesystem backup satisfy the
        // one indicator that says the central database is being dumped —
        // while it had not been dumped in weeks.
        $this->makeRecord([
            'type' => 'filesystem',
            'tenant_id' => null,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $row = collect($this->getJson('/vanguard/api/health')->assertOk()->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertNull(
            $row['last_success_at'],
            'a filesystem backup is not a landlord backup and must not answer for one',
        );
        $this->assertTrue($row['stale']);
    }

    #[Test]
    public function a_landlord_backup_makes_the_landlord_row_green(): void
    {
        $this->makeRecord([
            'type' => 'landlord',
            'tenant_id' => null,
            'status' => 'completed',
            'completed_at' => now()->subMinute(),
        ]);

        $row = collect($this->getJson('/vanguard/api/health')->assertOk()->json('freshness.targets'))
            ->firstWhere('target', 'landlord');

        $this->assertNotNull($row['last_success_at']);
        $this->assertFalse($row['stale']);
    }
}
