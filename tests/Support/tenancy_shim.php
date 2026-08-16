<?php

/**
 * Declares SoftArtisan\Vanguard\Services\tenancy(), intercepting the
 * unqualified tenancy() calls made by TenancyResolver::runForTenant() and
 * RestoreService::restoreTenant().
 *
 * PHP resolves an unqualified function call made from inside a namespace by
 * looking for <that namespace>\<name>() first, and only falls back to the
 * global function if none exists. Declaring the function here, in the same
 * namespace as the calling code, is what lets these tests drive multi-tenant
 * behaviour without stancl/tenancy installed — no production code is
 * touched, and no dependency is added.
 *
 * This file is not autoloaded (it declares a function, not a class covered
 * by the package's PSR-4 map) — it must be require_once'd before any test
 * that exercises TenancyResolver::runForTenant() or RestoreService::restore()
 * for a tenant backup.
 */

namespace SoftArtisan\Vanguard\Services;

use SoftArtisan\Vanguard\Tests\Support\FakeTenancyManager;

if (! function_exists(__NAMESPACE__.'\\tenancy')) {
    function tenancy(): FakeTenancyManager
    {
        return FakeTenancyManager::instance();
    }
}
