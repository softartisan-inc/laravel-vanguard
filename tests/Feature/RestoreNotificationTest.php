<?php

namespace SoftArtisan\Vanguard\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
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
}
