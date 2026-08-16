<?php

namespace SoftArtisan\Vanguard\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class VanguardActorTest extends TestCase
{
    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        parent::tearDown();
    }

    #[Test]
    public function it_has_no_actor_when_nobody_is_authenticated(): void
    {
        $this->assertNull(Vanguard::actor());
    }

    #[Test]
    public function it_uses_the_configured_callback(): void
    {
        // The package cannot presume the host application's user model, so the
        // application says who is acting.
        Vanguard::restoreActor(fn () => 'ops:henoc');

        $this->assertSame('ops:henoc', Vanguard::actor());
    }

    #[Test]
    public function it_casts_whatever_the_callback_returns_to_a_string(): void
    {
        Vanguard::restoreActor(fn () => 42);

        $this->assertSame('42', Vanguard::actor());
    }
}
