<?php

namespace SoftArtisan\Vanguard\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal, self-contained stand-in for a stancl/tenancy Tenant model.
 *
 * stancl/tenancy is not installed in the test environment (it is a suggested
 * package, not a dependency — see composer.json). This model backs a plain
 * table created ad hoc by the isolation tests, and carries just enough shape
 * to drive TenancyResolver / BackupManager / RestoreService the same way a
 * real tenant model would: a tenant key, a per-tenant database connection
 * config, and a per-tenant storage path.
 */
class FakeTenant extends Model
{
    protected $table = 'vanguard_test_tenants';

    protected $guarded = [];

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Mirrors Stancl\Tenancy\Contracts\Tenant::getTenantKey(), which every
     * production call site in BackupManager/RestoreService relies on.
     */
    public function getTenantKey(): string
    {
        return (string) $this->getKey();
    }

    /**
     * The database connection config this tenant resolves to once
     * "tenancy" is initialized for it. Includes a marker key so tests can
     * assert on tenant identity without depending on the full config shape.
     *
     * @return array<string, mixed>
     */
    public function dbConfig(): array
    {
        return [
            'driver' => 'sqlite',
            'database' => (string) $this->db_database,
            'vanguard_test_marker' => $this->getTenantKey(),
        ];
    }

    /**
     * The filesystem root this tenant resolves to once "tenancy" is
     * initialized for it — mirrors stancl/tenancy's storage_path() override.
     */
    public function storagePath(): string
    {
        return (string) $this->storage_path;
    }
}
