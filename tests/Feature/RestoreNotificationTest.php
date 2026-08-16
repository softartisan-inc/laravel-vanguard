<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Notifications\RestoreOutcomeNotification;
use SoftArtisan\Vanguard\Tests\TestCase;

class RestoreNotificationTest extends TestCase
{
    #[Test]
    public function it_mails_the_operator_when_a_restore_fails(): void
    {
        // A failed restore is at least as alarming as a failed backup, and
        // nobody was watching it.
        Notification::fake();

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.enabled' => true,
            'vanguard.notifications.mail.to' => 'ops@example.com',
        ]);

        $restore = $this->makeRestore(['status' => 'failed', 'tenant_id' => '9001', 'type' => 'tenant']);

        event(new RestoreFailed($restore, new RuntimeException('Checksum mismatch for backup #7.')));

        Notification::assertSentOnDemand(
            RestoreOutcomeNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.com',
        );
    }

    #[Test]
    public function it_stays_quiet_when_failure_notifications_are_off(): void
    {
        Notification::fake();

        config([
            'vanguard.notifications.on_failure' => false,
            'vanguard.notifications.mail.to' => 'ops@example.com',
        ]);

        event(new RestoreFailed($this->makeRestore(['status' => 'failed']), new RuntimeException('boom')));

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_mail_names_the_target_and_carries_the_error(): void
    {
        $restore = $this->makeRestore(['status' => 'failed', 'tenant_id' => '9001', 'type' => 'tenant']);

        $mail = (new RestoreOutcomeNotification($restore, 'Checksum mismatch for backup #7.'))
            ->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('tenant 9001', $mail->subject);
        $this->assertNotEmpty(array_filter(
            $mail->introLines,
            fn (string $line) => str_contains($line, 'Checksum mismatch'),
        ));
    }

    #[Test]
    public function it_posts_to_slack_when_a_webhook_is_configured(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.to' => null,
            'vanguard.notifications.slack.enabled' => true,
            'vanguard.notifications.slack.webhook_url' => 'https://hooks.slack.test/abc',
        ]);

        $restore = $this->makeRestore(['status' => 'failed', 'tenant_id' => '9001', 'type' => 'tenant']);

        event(new RestoreFailed($restore, new RuntimeException('Checksum mismatch for backup #7.')));

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/abc'
            && str_contains($request['text'], 'restore')
            && str_contains($request['text'], 'Checksum mismatch for backup #7.'));
    }

    #[Test]
    public function a_failing_channel_never_fails_the_restore(): void
    {
        // A restore failure must reach the operator even if the webhook
        // itself is unreachable — that outage must not become a second,
        // silent failure.
        Http::fake(fn () => throw new RuntimeException('slack unreachable'));

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.to' => null,
            'vanguard.notifications.slack.enabled' => true,
            'vanguard.notifications.slack.webhook_url' => 'https://hooks.slack.test/abc',
        ]);

        event(new RestoreFailed($this->makeRestore(['status' => 'failed']), new RuntimeException('boom')));

        $this->assertTrue(true, 'the event dispatch must not bubble the channel error');
    }
}
