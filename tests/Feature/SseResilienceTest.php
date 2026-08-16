<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Http\Controllers\SseController;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * A database blip must cost one poll, not every open dashboard.
 *
 * The poll reconnects to the database every couple of seconds. An error raised
 * mid-loop propagated out of the streamed response, so a transient failure —
 * a restarted database, a dropped connection during a long restore — tore down
 * every dashboard stream at once instead of skipping a single cycle.
 */
class SseResilienceTest extends TestCase
{
    /**
     * The poll genuinely disconnects and reconnects, which is the behaviour
     * under test — and which empties an `:memory:` SQLite database the moment
     * it happens. This class therefore runs against a real file, recreated for
     * every test.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $file = sys_get_temp_dir().'/vanguard_sse_resilience.sqlite';

        @unlink($file);
        touch($file);

        $app['config']->set('database.connections.sqlite.database', $file);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink(sys_get_temp_dir().'/vanguard_sse_resilience.sqlite');
    }

    #[Test]
    public function a_healthy_poll_returns_the_snapshot(): void
    {
        $this->makeRecord(['status' => 'completed']);

        $controller = new PollableSseController;

        $this->assertNotNull($controller->poll());
    }

    #[Test]
    public function a_database_error_mid_poll_costs_the_poll_and_nothing_more(): void
    {
        $controller = new FailingSseController;

        $this->assertNull($controller->poll(), 'the cycle is skipped, the stream is not');
    }

    #[Test]
    public function it_says_which_error_it_swallowed(): void
    {
        // Swallowed silently, a database that answers one poll in three looks
        // exactly like a system where nothing is happening.
        $logger = Log::spy();

        (new FailingSseController)->poll();

        $logger->shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, '[Vanguard]')
                && str_contains((string) ($context['error'] ?? ''), 'server has gone away'),
        );
    }

    #[Test]
    public function the_stream_keeps_running_through_a_blip_and_closes_normally(): void
    {
        // The whole loop, not just the helper: the first snapshot succeeds,
        // the poll that follows throws, and the stream must still send its
        // heartbeat and close on its own terms.
        config([
            'vanguard.realtime.sse_interval' => 1,
            'vanguard.realtime.max_lifetime' => 1,
        ]);

        $response = (new BlipOnceSseController)->stream(Request::create('/vanguard/api/stream'));

        // Two buffers: the stream closes the innermost one on purpose, so the
        // events it then echoes land in the outer one this test owns.
        ob_start();
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertStringContainsString('event: connected', $output);
        $this->assertStringContainsString(': heartbeat', $output);
        $this->assertStringContainsString('event: close', $output);
    }
}

/**
 * Exposes the poll so it can be exercised without opening a stream.
 */
class PollableSseController extends SseController
{
    public function poll(): ?string
    {
        return $this->pollSnapshot();
    }
}

/**
 * A database that is down for every poll.
 */
class FailingSseController extends PollableSseController
{
    protected function snapshot(): string
    {
        throw new RuntimeException('SQLSTATE[HY000] [2006] MySQL server has gone away');
    }
}

/**
 * A database that answers the first snapshot and fails the next one.
 */
class BlipOnceSseController extends SseController
{
    protected int $calls = 0;

    protected function snapshot(): string
    {
        $this->calls++;

        if ($this->calls > 1) {
            throw new RuntimeException('SQLSTATE[HY000] [2006] MySQL server has gone away');
        }

        return 'first';
    }
}
