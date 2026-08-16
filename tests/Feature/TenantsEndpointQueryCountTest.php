<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

/**
 * The tenants screen ran two queries per tenant.
 *
 * On the installation this package was written for, that is a query count that
 * grows with the customer list — the one number nobody notices until the page
 * that shows whether backups work is the slowest page in the product.
 */
class TenantsEndpointQueryCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Vanguard::auth(fn ($request) => true);
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

    #[Test]
    public function it_reads_the_whole_screen_in_a_fixed_number_of_queries(): void
    {
        // Two queries per tenant before this: the latest record, then a count.
        // Five tenants therefore cost ten round trips, fifty tenants a hundred.
        $keys = ['9001', '9002', '9003', '9004', '9005'];

        $this->withTenants($keys);

        foreach ($keys as $key) {
            $this->makeRecord(['type' => 'tenant', 'tenant_id' => $key, 'status' => 'completed']);
            $this->makeRecord(['type' => 'tenant', 'tenant_id' => $key, 'status' => 'failed']);
        }

        $queries = $this->countQueries(fn () => $this->getJson('/vanguard/api/tenants')->assertOk());

        $this->assertLessThanOrEqual(
            3,
            $queries,
            "the endpoint ran {$queries} queries for five tenants: the count is following the tenant list",
        );
    }

    #[Test]
    public function it_still_reports_each_tenants_latest_backup_and_total(): void
    {
        $this->withTenants(['9001', '9002']);

        $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001', 'status' => 'failed']);
        $latest = $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9001', 'status' => 'completed']);
        $this->makeRecord(['type' => 'tenant', 'tenant_id' => '9002', 'status' => 'completed']);

        $response = $this->getJson('/vanguard/api/tenants')->assertOk();

        $first = collect($response->json('tenants'))->firstWhere('id', '9001');
        $second = collect($response->json('tenants'))->firstWhere('id', '9002');

        $this->assertSame(2, $first['total_backups']);
        $this->assertSame($latest->id, $first['latest_backup']['id']);
        $this->assertSame('completed', $first['latest_backup']['status']);

        $this->assertSame(1, $second['total_backups']);
    }

    #[Test]
    public function a_tenant_that_has_never_been_backed_up_reports_nothing_rather_than_breaking(): void
    {
        $this->withTenants(['9001']);

        $response = $this->getJson('/vanguard/api/tenants')->assertOk();

        $tenant = collect($response->json('tenants'))->firstWhere('id', '9001');

        $this->assertNull($tenant['latest_backup']);
        $this->assertSame(0, $tenant['total_backups']);
    }
}
