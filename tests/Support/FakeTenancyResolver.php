<?php

namespace SoftArtisan\Vanguard\Tests\Support;

use SoftArtisan\Vanguard\Services\TenancyResolver;

/**
 * The real TenancyResolver, minus the one check that only stancl/tenancy
 * being installed can satisfy.
 *
 * TenancyResolver::isEnabled() is hard-gated on
 * interface_exists(\Stancl\Tenancy\Contracts\Tenant::class), which is always
 * false in this test environment since stancl/tenancy is a suggested
 * package, not a dependency. Overriding just that one method lets
 * allTenants(), findTenant(), runForTenant(), tenantDbConfig() and
 * landlordDbConfig() run as the real, unmodified production code — driven by
 * config('vanguard.tenancy.tenant_model') pointing at FakeTenant and by the
 * tenancy() shim in tenancy_shim.php.
 */
class FakeTenancyResolver extends TenancyResolver
{
    public function isEnabled(): bool
    {
        return true;
    }
}
