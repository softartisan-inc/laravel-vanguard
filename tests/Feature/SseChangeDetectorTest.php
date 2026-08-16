<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Http\Controllers\SseController;
use SoftArtisan\Vanguard\Models\BackupRecord;
use SoftArtisan\Vanguard\Tests\TestCase;

class SseChangeDetectorTest extends TestCase
{
    protected function detector(): ProbeableSseController
    {
        return new ProbeableSseController;
    }

    #[Test]
    public function an_idle_system_produces_the_same_fingerprint_twice(): void
    {
        $this->makeRecord(['status' => 'completed']);

        $detector = $this->detector();

        $this->assertSame($detector->fingerprint(), $detector->fingerprint());
    }

    #[Test]
    public function it_sees_two_transitions_that_cancel_each_other_out(): void
    {
        // The regression. The old snapshot was a status→count map plus the
        // latest id — a lossy aggregate. These two transitions leave the
        // multiset of statuses identical (one running, one completed, before
        // and after) and the maximum id untouched, so the old detector
        // emitted nothing at all. The spec records this exact shape being hit
        // in production on 16 August with --all-tenants and one worker.
        $finishing = $this->makeRecord(['status' => 'running']);
        $starting = $this->makeRecord(['status' => 'completed']);

        $before = $this->detector()->fingerprint();

        $finishing->update(['status' => 'completed']);
        $starting->update(['status' => 'running']);

        $this->assertNotSame(
            $before,
            $this->detector()->fingerprint(),
            'a state change that the counts happen to cancel is still a state change',
        );
    }

    #[Test]
    public function it_sees_a_single_backup_changing_status(): void
    {
        $record = $this->makeRecord(['status' => 'running']);

        $before = $this->detector()->fingerprint();

        $record->update(['status' => 'completed']);

        $this->assertNotSame($before, $this->detector()->fingerprint());
    }

    #[Test]
    public function it_sees_a_new_backup_appear(): void
    {
        $before = $this->detector()->fingerprint();

        $this->makeRecord(['status' => 'running']);

        $this->assertNotSame($before, $this->detector()->fingerprint());
    }

    #[Test]
    public function it_sees_a_backup_disappear(): void
    {
        $doomed = $this->makeRecord(['status' => 'completed']);
        $this->makeRecord(['status' => 'completed']);

        $before = $this->detector()->fingerprint();

        // A prune removes rows without changing the maximum id.
        $doomed->delete();

        $this->assertNotSame($before, $this->detector()->fingerprint());
    }

    #[Test]
    public function it_sees_a_restore_at_all(): void
    {
        // The old detector never queried vanguard_restores. Every restore was
        // invisible to the live channel, which is the channel the restore
        // screen is built on.
        $before = $this->detector()->fingerprint();

        $this->makeRestore(['status' => 'pending']);

        $this->assertNotSame($before, $this->detector()->fingerprint());
    }

    #[Test]
    public function it_sees_a_restore_move_from_one_phase_to_the_next(): void
    {
        // A seven-minute restore changes status twice and phase five times.
        // Without the phase in the fingerprint the operator watches a frozen
        // screen and concludes it has hung.
        $restore = $this->makeRestore(['status' => 'running', 'phase' => 'downloading']);

        $before = $this->detector()->fingerprint();

        $restore->markPhase('restoring database');

        $this->assertNotSame($before, $this->detector()->fingerprint());
    }

    #[Test]
    public function it_stays_bounded_on_a_long_history(): void
    {
        // Cheap enough to run every two seconds on an installation with years
        // of history: the window is capped and the columns are indexed.
        for ($i = 0; $i < SseController::RECENT_ROWS + 25; $i++) {
            $this->makeRecord(['status' => 'completed']);
        }

        $detector = $this->detector();
        $before = $detector->fingerprint();

        // A row far outside the window cannot change anything, by design.
        BackupRecord::orderBy('id')->first()->update(['status' => 'failed']);

        $this->assertSame($before, $detector->fingerprint());
        $this->assertSame(32, strlen($before), 'the fingerprint is a fixed-size digest, not the rows themselves');
    }
}

/**
 * Exposes the protected detector so it can be fingerprinted directly, without
 * opening a streaming response that never ends.
 */
class ProbeableSseController extends SseController
{
    public function fingerprint(): string
    {
        return $this->snapshot();
    }
}
