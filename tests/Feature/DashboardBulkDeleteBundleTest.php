<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Http\Controllers\BackupsApiController;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The half of the bulk delete that lives in the browser.
 *
 * Only three things about it are readable from PHP, and all three are the
 * ones that break silently: the path the client posts to, the fact that it
 * asks for the phrase rather than a yes/no, and that it never falls back to
 * the browser's own confirm(). Whether the check boxes tick, whether the
 * count in the dialog is the count of the selection — a human with a browser
 * answers that.
 */
class DashboardBulkDeleteBundleTest extends TestCase
{
    protected function bundle(): string
    {
        return $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();
    }

    #[Test]
    public function the_shipped_bundle_posts_to_the_endpoint_that_exists(): void
    {
        $this->assertStringContainsString('/backups/bulk-delete', $this->bundle());

        // And that path is routed, which is the other half of the agreement.
        $this->assertTrue(
            app('router')->has('vanguard.api.backups.bulk-destroy'),
            'the dashboard posts a bulk delete to a route the package does not declare',
        );
    }

    #[Test]
    public function the_shipped_bundle_asks_for_the_phrase_the_server_expects(): void
    {
        $bundle = $this->bundle();

        $this->assertStringContainsString('Type the phrase to confirm', $bundle);

        // The wording, as far as minification leaves it readable: the count is
        // interpolated and the noun is pluralised, exactly as
        // BackupsApiController::bulkDeleteTarget() builds it.
        $this->assertStringContainsString('delete ', $bundle);
        $this->assertStringContainsString('backup${', $bundle);
    }

    #[Test]
    public function the_shipped_bundle_never_falls_back_to_the_browsers_own_prompt(): void
    {
        $bundle = $this->bundle();

        // A suppressed confirm() returns false silently, and the button would
        // simply stop working with no sign why. The theme's dialog, always.
        $this->assertStringNotContainsString('window.confirm', $bundle);
        $this->assertStringNotContainsString('confirm(', $bundle);
    }

    #[Test]
    public function the_bulk_ceiling_is_the_one_the_dialog_can_ever_ask_for(): void
    {
        // A page is fifteen rows and the selection is per page, so the ceiling
        // is never reachable from the interface — it bounds curl, not clicks.
        $this->assertGreaterThan(15, BackupsApiController::BULK_DELETE_MAX);
    }

    #[Test]
    public function the_shipped_stylesheet_carries_the_bulk_dialogs_own_rules(): void
    {
        $this->get('/vanguard/assets/vanguard.css')
            ->assertOk()
            ->assertSee('bulk-summary', false)
            ->assertSee('row-selected', false);
    }
}
