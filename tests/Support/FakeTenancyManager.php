<?php

namespace SoftArtisan\Vanguard\Tests\Support;

/**
 * Stand-in for the object stancl/tenancy's global tenancy() helper returns.
 *
 * TenancyResolver::runForTenant() and RestoreService::restoreTenant() both
 * call the unqualified tenancy() function. Because both classes live in the
 * SoftArtisan\Vanguard\Services namespace, PHP resolves that unqualified call
 * against SoftArtisan\Vanguard\Services\tenancy() first, falling back to the
 * global function only if no such namespaced function exists (see
 * tests/Support/tenancy_shim.php). That lets this fake intercept every call
 * without touching production code or installing stancl/tenancy.
 *
 * initialize()/end() mirror what stancl/tenancy actually does on the two
 * axes Vanguard depends on: it swaps the tenant DB connection config, and it
 * repoints storage_path() at the tenant's own root.
 */
class FakeTenancyManager
{
    private static ?self $instance = null;

    private mixed $active = null;

    private int $initializeCalls = 0;

    private int $endCalls = 0;

    private ?string $landlordStoragePath = null;

    private string $tenantConnectionName = 'tenant';

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * Drop the singleton so the next tenancy() call starts a fresh instance.
     * Call from test setUp()/tearDown() to prevent state leaking between tests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Configure the fake for a test. Must run before any initialize()/end() call.
     */
    public function boot(string $tenantConnectionName, string $landlordStoragePath): void
    {
        $this->tenantConnectionName = $tenantConnectionName;
        $this->landlordStoragePath = $landlordStoragePath;
        $this->active = null;
        $this->initializeCalls = 0;
        $this->endCalls = 0;
    }

    public function initialize(mixed $tenant): void
    {
        $this->active = $tenant;
        $this->initializeCalls++;

        config(["database.connections.{$this->tenantConnectionName}" => $tenant->dbConfig()]);
        app()->useStoragePath($tenant->storagePath());
    }

    public function end(): void
    {
        $this->active = null;
        $this->endCalls++;

        // Null out the tenant connection rather than leaving the last
        // tenant's config behind: a bug that reads tenantDbConfig() outside
        // a real tenancy context should throw, not silently reuse stale data.
        config(["database.connections.{$this->tenantConnectionName}" => null]);

        if ($this->landlordStoragePath !== null) {
            app()->useStoragePath($this->landlordStoragePath);
        }
    }

    public function activeTenant(): mixed
    {
        return $this->active;
    }

    public function initializeCalls(): int
    {
        return $this->initializeCalls;
    }

    public function endCalls(): int
    {
        return $this->endCalls;
    }
}
