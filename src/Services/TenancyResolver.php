<?php

namespace SoftArtisan\Vanguard\Services;

use RuntimeException;

class TenancyResolver
{
    protected bool $tenancyAvailable;

    /**
     * Initialise the resolver by probing whether stancl/tenancy is installed and enabled.
     */
    public function __construct()
    {
        $this->tenancyAvailable = config('vanguard.tenancy.enabled', true)
            && interface_exists(\Stancl\Tenancy\Contracts\Tenant::class);
    }

    /**
     * Whether multi-tenancy is enabled and the stancl/tenancy package is available.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->tenancyAvailable;
    }

    /**
     * Get all tenant instances.
     *
     * Returns an empty collection when tenancy is disabled.
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
     * Find a tenant by its primary key.
     *
     * @param  string  $tenantKey
     * @return mixed  The tenant model instance
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findTenant(string $tenantKey): mixed
    {
        $model = config('vanguard.tenancy.tenant_model', \App\Models\Tenant::class);
        return $model::findOrFail($tenantKey);
    }

    /**
     * Initialize tenancy context for a tenant, run a callback, then end the context.
     *
     * The tenancy context is always ended in a finally block, even if the callback throws.
     *
     * @param  mixed     $tenant    A tenant model instance
     * @param  callable  $callback  Receives the tenant as its first argument
     * @return mixed  The return value of the callback
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
     *
     * stancl/tenancy switches the connection automatically after initialize().
     * Must be called inside a runForTenant() callback.
     *
     * @return array  Laravel database connection config array
     *
     * @throws RuntimeException If the tenant connection is not configured
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
     *
     * Tenants may optionally have a 'vanguard_schedule' attribute containing a
     * cron expression that overrides the global schedule.
     *
     * @param  mixed  $tenant  Tenant model instance
     * @return string|null  Cron expression, or null if not configured
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
     *
     * Reads the central connection name from stancl/tenancy config, falling
     * back to the application default connection.
     *
     * @return array  Laravel database connection config array
     */
    public function landlordDbConfig(): array
    {
        // stancl/tenancy v3 exposes the landlord connection via config
        $connection = config('tenancy.database.central_connection', config('database.default'));
        return config("database.connections.{$connection}");
    }
}
