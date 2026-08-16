<?php

namespace SoftArtisan\Vanguard\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class VanguardCentralConnectionTest extends TestCase
{
    #[Test]
    public function it_resolves_the_central_connection_named_by_the_tenancy_config(): void
    {
        config(['tenancy.database.central_connection' => 'landlord_mysql']);

        $this->assertSame('landlord_mysql', Vanguard::centralConnection());
    }

    #[Test]
    public function it_falls_back_to_the_default_connection_when_tenancy_names_none(): void
    {
        // Never the literal 'central'. On this product's production installs
        // the central connection IS the application default (mysql), and a
        // connection called 'central' does not exist at all — hardcoding it
        // has shipped as a bug three times in a sibling package.
        config(['tenancy.database.central_connection' => null]);
        config(['database.default' => 'sqlite']);

        $this->assertSame('sqlite', Vanguard::centralConnection());
    }

    #[Test]
    public function it_falls_back_when_the_tenancy_config_is_absent_entirely(): void
    {
        // A host application without stancl/tenancy has no tenancy config file.
        config()->offsetUnset('tenancy');
        config(['database.default' => 'sqlite']);

        $this->assertSame('sqlite', Vanguard::centralConnection());
    }
}
