<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * The restore history, in the interface that is supposed to show it.
 *
 * `GET /vanguard/api/restores` has answered since 2.3.0 — paginated,
 * filterable, carrying the operator and the verbatim error — and until now
 * nothing in the dashboard ever called it. The only restores an operator
 * could see were the ones In Progress lists: running, pending, and failures
 * from the last twenty-four hours. A restore that *succeeded* vanished from
 * the screen the second it finished, and one run last week was unfindable in
 * either state.
 *
 * `public/vanguard.js` is versioned in the package and served by
 * AssetsController, so a page added under resources/ and never rebuilt ships
 * nothing at all. These assertions read the served bundle, which is what makes
 * them fail on a stale one.
 */
class DashboardRestoreHistoryBundleTest extends TestCase
{
    protected function bundle(): string
    {
        return $this->get('/vanguard/assets/vanguard.js')->assertOk()->getContent();
    }

    #[Test]
    public function the_shipped_bundle_reads_the_restore_history(): void
    {
        // The path as the client composes it: useApi() prefixes the base
        // path and /api itself, so what the bundle carries is the tail.
        $this->assertStringContainsString(
            '/restores?',
            $this->bundle(),
            'no screen in the dashboard ever calls the restore history endpoint',
        );
    }

    #[Test]
    public function the_shipped_bundle_offers_a_restore_history_screen(): void
    {
        $this->assertStringContainsString('Restores', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_names_who_asked_for_a_restore(): void
    {
        // "Did somebody restore this, and who?" is the question the history
        // exists to answer; a table without this column answers half of it.
        $this->assertStringContainsString('requested_by', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_says_whether_a_restore_came_from_the_console(): void
    {
        // Beside the name, never inside it: the column that answers "who"
        // holds an identity, and the channel is its own fact.
        $this->assertStringContainsString('origin', $this->bundle());
        $this->assertStringContainsString('origin-tag', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_shows_why_a_restore_failed(): void
    {
        $this->assertStringContainsString('restore-error', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_marks_a_rehearsal_as_one(): void
    {
        // A rehearsal is a completed restore that never touched the target.
        // Rendered like any other, it reports data replaced that was not.
        $this->assertStringContainsString('target_database', $this->bundle());
        $this->assertStringContainsString('Rehearsal', $this->bundle());
    }

    #[Test]
    public function the_shipped_bundle_can_filter_the_history_by_status(): void
    {
        $this->assertStringContainsString('restore-status-filter', $this->bundle());
    }
}
