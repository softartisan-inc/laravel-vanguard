<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * What the served bundle may and may not do when the live channel ticks.
 *
 * These assertions cannot prove the screen no longer blinks — no JS runner
 * exists here, and "the scroll position survived" is something a human sees in
 * a browser. What they can do is pin the two facts that are readable from PHP:
 * the shipped bundle never reloads the window, and it carries the detail panel
 * whose open state is what a refresh used to destroy. Both fail on a bundle
 * that was edited under resources/ and never rebuilt, which is the failure
 * this package has no other way to catch.
 */
class DashboardLiveUpdateBundleTest extends TestCase
{
    protected function bundle(): string
    {
        return $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();
    }

    #[Test]
    public function the_shipped_bundle_never_reloads_the_window(): void
    {
        $bundle = $this->bundle();

        foreach (['location.reload', 'location.href =', 'window.location='] as $reload) {
            $this->assertStringNotContainsString(
                $reload,
                $bundle,
                'a live tick refreshes a collection; it does not throw the operator back to the top of a new page',
            );
        }
    }

    #[Test]
    public function the_shipped_bundle_carries_the_detail_panel_a_tick_must_not_close(): void
    {
        $bundle = $this->bundle();

        $this->assertStringContainsString('Hide details', $bundle);
        $this->assertStringContainsString('row-detail', $bundle);
    }

    #[Test]
    public function the_shipped_stylesheet_carries_the_detail_panels_own_rules(): void
    {
        $this->get('/vanguard/assets/vanguard.css')
            ->assertOk()
            ->assertSee('row-detail', false);
    }
}
