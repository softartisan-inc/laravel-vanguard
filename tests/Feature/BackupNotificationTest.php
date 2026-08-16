<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Notifications\BackupOutcomeNotification;
use SoftArtisan\Vanguard\Tests\TestCase;

/**
 * config('vanguard.notifications') described behaviour no code implemented:
 * setting VANGUARD_NOTIFY_MAIL bought silence, which is how a backup that
 * reaches no destination goes unnoticed for months.
 */
class BackupNotificationTest extends TestCase
{
    #[Test]
    public function it_mails_the_operator_when_a_backup_fails(): void
    {
        Notification::fake();

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.enabled' => true,
            'vanguard.notifications.mail.to' => 'ops@example.com',
        ]);

        $record = $this->makeRecord(['status' => 'failed', 'error' => 'mysqldump: Access denied']);

        event(new BackupFailed($record, new RuntimeException('mysqldump: Access denied')));

        Notification::assertSentOnDemand(
            BackupOutcomeNotification::class,
            function (BackupOutcomeNotification $notification, array $channels, object $notifiable) {
                return $notification->failed
                    && $notifiable->routes['mail'] === 'ops@example.com';
            },
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

        event(new BackupFailed($this->makeRecord(['status' => 'failed']), new RuntimeException('boom')));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_stays_quiet_when_no_address_is_configured(): void
    {
        Notification::fake();

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.to' => null,
        ]);

        event(new BackupFailed($this->makeRecord(['status' => 'failed']), new RuntimeException('boom')));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_mail_on_success_unless_asked_to(): void
    {
        Notification::fake();

        config([
            'vanguard.notifications.on_success' => false,
            'vanguard.notifications.mail.to' => 'ops@example.com',
        ]);

        event(new BackupCompleted($this->makeRecord()));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_mails_on_success_when_asked_to(): void
    {
        Notification::fake();

        config([
            'vanguard.notifications.on_success' => true,
            'vanguard.notifications.mail.enabled' => true,
            'vanguard.notifications.mail.to' => 'ops@example.com',
        ]);

        event(new BackupCompleted($this->makeRecord()));

        Notification::assertSentOnDemand(
            BackupOutcomeNotification::class,
            fn (BackupOutcomeNotification $notification) => ! $notification->failed,
        );
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

        event(new BackupFailed($this->makeRecord(['status' => 'failed']), new RuntimeException('disk full')));

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/abc'
            && str_contains($request['text'], 'failed')
            && str_contains($request['text'], 'disk full'));
    }

    #[Test]
    public function a_failing_channel_never_fails_the_backup(): void
    {
        // A backup that worked must not be reported as broken because the
        // mail server was down.
        Http::fake(fn () => throw new RuntimeException('slack unreachable'));

        config([
            'vanguard.notifications.on_failure' => true,
            'vanguard.notifications.mail.to' => null,
            'vanguard.notifications.slack.enabled' => true,
            'vanguard.notifications.slack.webhook_url' => 'https://hooks.slack.test/abc',
        ]);

        event(new BackupFailed($this->makeRecord(['status' => 'failed']), new RuntimeException('boom')));

        $this->assertTrue(true, 'the event dispatch must not bubble the channel error');
    }
}
