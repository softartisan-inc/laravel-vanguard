<?php

namespace SoftArtisan\Vanguard\Services;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
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
     * @return Collection<\Stancl\Tenancy\Contracts\Tenant>
     */
    public function allTenants(): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        $model = config('vanguard.tenancy.tenant_model', Tenant::class);

        if (! class_exists($model)) {
            return collect();
        }

        return $model::all();
    }

    /**
     * Find a tenant by its primary key.
     *
     * @return mixed The tenant model instance
     *
     * @throws ModelNotFoundException
     */
    public function findTenant(string $tenantKey): mixed
    {
        $model = config('vanguard.tenancy.tenant_model', Tenant::class);

        if (! class_exists($model)) {
            throw new RuntimeException("Tenant model [{$model}] not found. Configure vanguard.tenancy.tenant_model.");
        }

        return $model::findOrFail($tenantKey);
    }

    /**
     * Initialize tenancy context for a tenant, run a callback, then end the context.
     *
     * The tenancy context is always ended in a finally block, even if the callback throws.
     *
     * @param  mixed  $tenant  A tenant model instance
     * @param  callable  $callback  Receives the tenant as its first argument
     * @return mixed The return value of the callback
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
     * @return array Laravel database connection config array
     *
     * @throws RuntimeException If the tenant connection is not configured
     */
    public function tenantDbConfig(): array
    {
        $connection = config('tenancy.database.tenant_connection_name', 'tenant');
        $config = config("database.connections.{$connection}");

        if (! $config) {
            throw new RuntimeException(
                "Tenant DB connection [{$connection}] not found. ".
                'Ensure tenancy()->initialize() was called before resolving tenant DB config.'
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
     * @return string|null Cron expression, or null if not configured
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
     * @return array Laravel database connection config array
     */
    public function landlordDbConfig(): array
    {
        // stancl/tenancy v3 exposes the landlord connection via config
        $connection = config('tenancy.database.central_connection', config('database.default'));

        return config("database.connections.{$connection}");
    }

    /**
     * Get the DB connection config the landlord (global) dump runs through.
     *
     * vanguard.database.backup_connection names a connection of its own for
     * the dump — on a database isolated by Row-Level Security, the
     * application's role only sees the rows of one tenant at a time, so a
     * dump through it is a backup of that view, not of the database. The
     * dedicated connection is meant for a BYPASSRLS, read-only role.
     *
     * Unset (null or ''), this is landlordDbConfig() and nothing else: the
     * behaviour every installation had before the setting existed.
     *
     * Only the landlord dump moves. Per-tenant dumps of a multi-database
     * installation keep reading tenantDbConfig().
     *
     * @return array Laravel database connection config array
     *
     * @throws RuntimeException When the named connection is not declared, or
     *                          uses another driver than the landlord one
     */
    public function backupDbConfig(): array
    {
        $default = $this->landlordDbConfig();
        $name = $this->dedicatedConnectionName('vanguard.database.backup_connection');

        if ($name === null) {
            return $default;
        }

        return $this->dedicatedConnection('vanguard.database.backup_connection', $name, $default);
    }

    /**
     * Whether vanguard.database.restore_connection names a connection.
     */
    public function hasDedicatedRestoreConnection(): bool
    {
        return $this->dedicatedConnectionName('vanguard.database.restore_connection') !== null;
    }

    /**
     * Get the DB connection config a landlord restore writes through.
     *
     * vanguard.database.restore_connection names a connection of its own for
     * the restore — under FORCE ROW LEVEL SECURITY even the table owner is
     * filtered, so the restore needs an owner role with BYPASSRLS.
     *
     * Unset (null or ''), this is the application's default connection, which
     * is what the landlord restore has always written to.
     *
     * @return array Laravel database connection config array
     *
     * @throws RuntimeException When the named connection is not declared, or
     *                          uses another driver than the default one
     */
    public function restoreDbConfig(): array
    {
        $default = config('database.connections.'.config('database.default'));
        $name = $this->dedicatedConnectionName('vanguard.database.restore_connection');

        if ($name === null) {
            return $default;
        }

        return $this->dedicatedConnection('vanguard.database.restore_connection', $name, $default);
    }

    /**
     * The connection name a setting holds, or null when it is unset.
     *
     * An empty string counts as unset: `VANGUARD_BACKUP_CONNECTION=` in a
     * .env file yields '' rather than null.
     */
    protected function dedicatedConnectionName(string $key): ?string
    {
        $name = config($key);

        if ($name === null) {
            return null;
        }

        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * Resolve a dedicated connection, refusing rather than falling back.
     *
     * Falling back to the application's connection when the dedicated one is
     * missing would bring back exactly the failure the setting exists to
     * prevent — a dump that completes and holds only what the application's
     * role can see — so a missing or mismatched connection is an error.
     *
     * @param  string  $key  The config key the name was read from, for the message
     * @param  string  $name  The connection name
     * @param  array|null  $replaced  The connection it stands in for
     *
     * @throws RuntimeException
     */
    protected function dedicatedConnection(string $key, string $name, ?array $replaced): array
    {
        $config = config("database.connections.{$name}");

        if (! is_array($config) || $config === []) {
            throw new RuntimeException(
                "[Vanguard] {$key} names the connection [{$name}], which is not declared in config/database.php. "
                .'Declare it, or unset the setting to use the application connection.'
            );
        }

        $driver = $config['driver'] ?? null;
        $expected = is_array($replaced) ? ($replaced['driver'] ?? null) : null;

        if ($expected !== null && $driver !== $expected) {
            throw new RuntimeException(sprintf(
                '[Vanguard] %s names the connection [%s], whose driver [%s] differs from the driver [%s] '
                .'of the connection it replaces. The dedicated connection must reach the same database server type.',
                $key,
                $name,
                (string) $driver,
                (string) $expected,
            ));
        }

        return $config;
    }
}
