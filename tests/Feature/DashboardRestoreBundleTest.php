<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The dashboard the operator actually receives.
 *
 * `public/vanguard.js` is versioned in the package and served by
 * AssetsController, so an edit under resources/ that is not rebuilt ships a
 * stale interface — and there is no JS test runner here to catch it. These
 * assertions read the served bundle for the properties the restore contract
 * makes non-negotiable, which is also what makes them fail on a bundle that
 * was never rebuilt.
 *
 * The restore endpoint refuses any call whose `confirm` does not repeat the
 * target's name, and answers 202 with a pending restore id rather than a
 * finished restore. A bundle that posts no `confirm` gives the operator a
 * button that is refused every time, and one that says "completed" reports
 * something nothing has verified.
 */
class DashboardRestoreBundleTest extends TestCase
{
    protected function bundle(): string
    {
        return $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();
    }

    #[Test]
    public function the_shipped_bundle_sends_a_confirmation_with_a_restore(): void
    {
        $this->assertStringContainsString(
            'confirm:',
            $this->bundle(),
            'the Restore button posts no confirm and is refused with a 400 every time',
        );
    }

    #[Test]
    public function the_shipped_bundle_asks_the_operator_for_the_target_name(): void
    {
        // The server rule, mirrored client-side, so the button is inert until
        // what was typed matches — rather than discovering it through a 400
        // whose instruction the UI gives no way to obey.
        $this->assertStringContainsString('Type the target name to confirm', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_does_not_claim_a_restore_finished(): void
    {
        $bundle = $this->bundle();

        $this->assertStringNotContainsString(
            'Restore completed successfully',
            $bundle,
            'the endpoint answers 202 with a pending restore id; nothing has been restored yet',
        );

        $this->assertStringContainsString(
            'Restore queued',
            $bundle,
            'the toast has to say the restore was queued, and name the id it was queued as',
        );
        $this->assertStringContainsString('restore_id', $bundle);
    }

    #[Test]
    public function the_shipped_stylesheet_carries_the_dialogs_own_rules(): void
    {
        // Same staleness trap on the other half of the build: the confirmation
        // dialog introduced its own classes, and a CSS bundle left unbuilt
        // renders them as unstyled text.
        $this->get('/vanguard/assets/vanguard.css')
            ->assertOk()
            ->assertSee('form-hint', false);
    }
}
