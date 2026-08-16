<?php

namespace SoftArtisan\Vanguard\Tests\Unit\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use SoftArtisan\Vanguard\Http\Concerns\GuardsDestructiveActions;
use SoftArtisan\Vanguard\Tests\TestCase;
use SoftArtisan\Vanguard\Vanguard;

class GuardsDestructiveActionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Vanguard::$restoreActorUsing = null;
        Mockery::close();
        parent::tearDown();
    }

    /**
     * The trait under test, exposed through a throwaway host so the protected
     * methods can be called directly. No reflection: the visibility is part of
     * what the controllers rely on, and reflection would hide a change to it.
     */
    protected function guard(): object
    {
        return new class
        {
            use GuardsDestructiveActions {
                rejectUnlessConfirmed as public;
                trace as public;
            }
        };
    }

    #[Test]
    public function it_rejects_a_call_with_no_confirmation_at_all(): void
    {
        $response = $this->guard()->rejectUnlessConfirmed(Request::create('/', 'POST'), 'landlord');

        $this->assertNotNull($response, 'the confirmation is an API rule, not a courtesy of the interface');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('landlord', $response->getData(true)['expected']);
    }

    #[Test]
    public function it_rejects_a_confirmation_that_names_another_target(): void
    {
        $request = Request::create('/', 'POST', ['confirm' => '9002']);

        $response = $this->guard()->rejectUnlessConfirmed($request, '9001');

        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_a_confirmation_that_is_not_a_string(): void
    {
        // {"confirm": true} decodes to a boolean, and a loose comparison
        // against a non-empty target string would wave it through.
        $request = Request::create('/', 'POST', ['confirm' => true]);

        $this->assertNotNull($this->guard()->rejectUnlessConfirmed($request, 'landlord'));
    }

    #[Test]
    public function it_lets_an_exact_confirmation_through(): void
    {
        $request = Request::create('/', 'POST', ['confirm' => 'landlord']);

        $this->assertNull($this->guard()->rejectUnlessConfirmed($request, 'landlord'));
    }

    #[Test]
    public function it_names_the_actor_and_the_target_in_the_trace(): void
    {
        Vanguard::restoreActor(fn () => 'ops@in-immo.app');

        $logger = Log::spy();

        $this->guard()->trace('restore requested', 'tenant:9001', ['backup_id' => 42]);

        try {
            $logger->shouldHaveReceived('warning')->withArgs(
                fn (string $message, array $context) => $message === '[Vanguard] restore requested'
                    && $context['actor'] === 'ops@in-immo.app'
                    && $context['target'] === 'tenant:9001'
                    && $context['backup_id'] === 42,
            );
            $this->assertTrue(true, 'Log was called with correct arguments');
        } catch (\Exception $e) {
            $this->fail('Expected log call not found: '.$e->getMessage());
        }
    }

    #[Test]
    public function it_traces_an_unnamed_actor_rather_than_nothing_at_all(): void
    {
        // A trail with a hole where the name should be is still a trail; a
        // trail that is skipped because nobody could be named is not.
        Vanguard::$restoreActorUsing = null;

        $logger = Log::spy();

        $this->guard()->trace('backup downloaded', 'backup:7');

        try {
            $logger->shouldHaveReceived('warning')->withArgs(
                fn (string $message, array $context) => $message === '[Vanguard] backup downloaded'
                    && $context['actor'] === 'unknown'
                    && $context['target'] === 'backup:7',
            );
            $this->assertTrue(true, 'Log was called with correct arguments');
        } catch (\Exception $e) {
            $this->fail('Expected log call not found: '.$e->getMessage());
        }
    }

    #[Test]
    public function it_keeps_resolved_actor_and_target_authoritative_over_context_keys(): void
    {
        // The trace() method must resolve the actor and target values itself.
        // Context passed by the caller should never be able to override them.
        // A context array containing 'actor' or 'target' keys must not shadow
        // what trace() resolved — the audit trail's identity is authoritative.
        Vanguard::restoreActor(fn () => 'ops@system');

        $logger = Log::spy();

        // Caller tries to override actor and target in the context.
        $this->guard()->trace('restore requested', 'tenant:9001', [
            'actor' => 'malicious@attacker.com',
            'target' => 'tenant:hacked',
            'backup_id' => 42,
        ]);

        try {
            $logger->shouldHaveReceived('warning')->withArgs(
                fn (string $message, array $context) => $message === '[Vanguard] restore requested'
                    && $context['actor'] === 'ops@system'
                    && $context['target'] === 'tenant:9001'
                    && $context['backup_id'] === 42,
            );
            $this->assertTrue(true, 'Log was called with correct arguments, context keys did not override resolved values');
        } catch (\Exception $e) {
            $this->fail('Expected log call not found: '.$e->getMessage());
        }
    }
}
