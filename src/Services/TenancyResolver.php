<?php

namespace SoftArtisan\Vanguard\Services;

use RuntimeException;

class TenancyResolver
{
    protected bool $tenancyAvailable;

    public function __construct()
    {
        $this->tenancyAvailable = config('vanguard.tenancy.enabled', true)
            && interface_exists(\Stancl\Tenancy\Contracts\Tenant::class);
    }

    public function isEnabled(): bool
    {
        return $this->tenancyAvailable;
    }

    /**
     * Get all tenant instances.
     *
     * @return \Illuminate\Support\Collection<\Stancl\Tenancy\Contracts\Tenant>
     */
    public function allTenants(): \Illuminate\Support\Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $model = config('vanguard.tenancy.tenant_model', \App\Models\Tenant::class);
        return $model::all();
    }

    /**
     * Find a tenant by its key.
     */
    public function findTenant(string $tenantKey): mixed
    {
        $model = config('vanguard.tenancy.tenant_model', \App\Models\Tenant::class);
        return $model::findOrFail($tenantKey);
    }

    /**
     * Initialize tenancy context for a tenant, run callback, then end.
     */
    public function runForTenant(mixed $tenant, callable $callback): mixed
    {
        try {
            tenancy()->initialize($tenant);
            return $callback($tenant);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Get the current active DB connection config for the tenant.
     * stancl/tenancy switches the connection automatically after initialize().
     */
    public function tenantDbConfig(): array
    {
        $connection = config('tenancy.database.tenant_connection_name', 'tenant');
        $config     = config("database.connections.{$connection}");

        if (! $config) {
            throw new RuntimeException(
                "Tenant DB connection [{$connection}] not found. ".
                "Ensure tenancy()->initialize() was called before resolving tenant DB config."
            );
        }

        return $config;
    }

    /**
     * Get the tenant's custom backup schedule if set (optional column on tenant model).
     * Returns null if not configured.
     */
    public function tenantSchedule(mixed $tenant): ?string
    {
        // Tenants can have a 'vanguard_schedule' attribute (cron expression)
        // This is optional — the column does not need to exist
        try {
            return $tenant->vanguard_schedule ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the landlord (central) DB connection config.
     */
    public function landlordDbConfig(): array
    {
        // stancl/tenancy v3 exposes the landlord connection via config
        $connection = config('tenancy.database.central_connection', config('database.default'));
        return config("database.connections.{$connection}");
    }
}
