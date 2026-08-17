<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The in-progress screen, as far as the server can see it.
 *
 * The endpoint has its own tests; these pin the half that ships in the bundle
 * and would otherwise go stale silently: the screen exists in the navigation,
 * it reads the endpoint that is routed, and it renders the two things the
 * payload carries that no other screen does — the live phase of a restore and
 * the queue depth, including its "unreadable" case.
 *
 * Whether the elapsed times tick, and whether the warning is the first thing
 * the eye lands on, is for a human with a browser.
 */
class DashboardOperationsBundleTest extends TestCase
{
    protected function bundle(): string
    {
        return $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();
    }

    #[Test]
    public function the_shipped_bundle_offers_the_screen_and_reads_the_routed_endpoint(): void
    {
        $bundle = $this->bundle();

        $this->assertStringContainsString('In Progress', $bundle);
        $this->assertStringContainsString('/operations', $bundle);

        $this->assertTrue(
            app('router')->has('vanguard.api.operations'),
            'the screen polls an endpoint the package does not declare',
        );
    }

    #[Test]
    public function the_shipped_bundle_says_when_the_queue_could_not_be_read(): void
    {
        // A 0 for a queue nobody could reach is the lie this screen exists to
        // avoid: down and empty must not look the same.
        $this->assertStringContainsString('Unreadable', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_renders_what_is_running_and_what_waits(): void
    {
        $bundle = $this->bundle();

        $this->assertStringContainsString('Running now', $bundle);
        $this->assertStringContainsString('Queued behind it', $bundle);
        $this->assertStringContainsString('Nothing is running', $bundle);
    }

    #[Test]
    public function the_shipped_stylesheet_carries_the_screens_own_rules(): void
    {
        $this->get('/vanguard/assets/vanguard.css')
            ->assertOk()
            ->assertSee('ops-warning', false)
            ->assertSee('ops-elapsed', false);
    }
}
