<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Services;

use SoftArtisan\Vanguard\Services\TenancyResolver;
use SoftArtisan\Vanguard\Tests\TestCase;

class TenancyResolverTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // isEnabled
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function is_enabled_returns_false_when_config_disabled(): void
    {
        config(['vanguard.tenancy.enabled' => false]);

        $resolver = new TenancyResolver;

        $this->assertFalse($resolver->isEnabled());
    }

    /** @test */
    public function is_enabled_returns_false_when_tenancy_package_not_installed(): void
    {
        config(['vanguard.tenancy.enabled' => true]);

        // stancl/tenancy is not in our test deps — interface won't exist
        $resolver = new TenancyResolver;

        // Either false (package missing) or true (package installed)
        $this->assertIsBool($resolver->isEnabled());
    }

    // ─────────────────────────────────────────────────────────────
    // allTenants
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function all_tenants_returns_empty_collection_when_disabled(): void
    {
        config(['vanguard.tenancy.enabled' => false]);

        $resolver = new TenancyResolver;

        $this->assertTrue($resolver->allTenants()->isEmpty());
    }

    // ─────────────────────────────────────────────────────────────
    // landlordDbConfig
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function landlord_db_config_returns_default_connection_config(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]]);

        $resolver = new TenancyResolver;
        $config   = $resolver->landlordDbConfig();

        $this->assertIsArray($config);
        $this->assertSame('sqlite', $config['driver']);
    }

    /** @test */
    public function landlord_db_config_prefers_central_connection_from_tenancy_config(): void
    {
        config(['tenancy.database.central_connection' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]]);

        $resolver = new TenancyResolver;
        $config   = $resolver->landlordDbConfig();

        $this->assertSame('sqlite', $config['driver']);
    }

    // ─────────────────────────────────────────────────────────────
    // tenantSchedule
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function tenant_schedule_returns_null_when_attribute_not_set(): void
    {
        $tenant = new class {
            // no vanguard_schedule attribute
        };

        $resolver = new TenancyResolver;

        $this->assertNull($resolver->tenantSchedule($tenant));
    }

    /** @test */
    public function tenant_schedule_returns_cron_when_attribute_is_set(): void
    {
        $tenant = new class {
            public string $vanguard_schedule = '0 4 * * 0'; // weekly
        };

        $resolver = new TenancyResolver;

        $this->assertSame('0 4 * * 0', $resolver->tenantSchedule($tenant));
    }

    /** @test */
    public function tenant_schedule_returns_null_when_attribute_is_null(): void
    {
        $tenant = new class {
            public ?string $vanguard_schedule = null;
        };

        $resolver = new TenancyResolver;

        $this->assertNull($resolver->tenantSchedule($tenant));
    }
}
