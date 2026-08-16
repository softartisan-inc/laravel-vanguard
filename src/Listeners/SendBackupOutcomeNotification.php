<?php

namespace SoftArtisan\Vanguard\Listeners;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SoftArtisan\Vanguard\Events\BackupCompleted;
use SoftArtisan\Vanguard\Events\BackupFailed;
use SoftArtisan\Vanguard\Events\RestoreFailed;
use SoftArtisan\Vanguard\Models\RestoreRecord;
use SoftArtisan\Vanguard\Notifications\BackupOutcomeNotification;
use SoftArtisan\Vanguard\Notifications\RestoreOutcomeNotification;

/**
 * Turns a backup outcome into the notifications config('vanguard.notifications')
 * promises.
 *
 * Until 2.0.1 that config was read by nobody: setting VANGUARD_NOTIFY_MAIL
 * bought silence, which is how a broken destination can go unnoticed for
 * months. The channels are opt-in and a channel that throws is logged rather
 * than allowed to turn a successful backup into a failed one.
 */
class SendBackupOutcomeNotification
{
    public function handleFailure(BackupFailed $event): void
    {
        if (! config('vanguard.notifications.on_failure', true)) {
            return;
        }

        $this->send($event->record, true, $event->exception->getMessage());
    }

    public function handleSuccess(BackupCompleted $event): void
    {
        if (! config('vanguard.notifications.on_success', false)) {
            return;
        }

        $this->send($event->record, false);
    }

    protected function send($record, bool $failed, ?string $error = null): void
    {
        $this->mail($record, $failed, $error);
        $this->slack($record, $failed, $error);
    }

    protected function mail($record, bool $failed, ?string $error): void
    {
        $to = config('vanguard.notifications.mail.to');

        if (! config('vanguard.notifications.mail.enabled', true) || empty($to)) {
            return;
        }

        try {
            Notification::route('mail', $to)
                ->notify(new BackupOutcomeNotification($record, $failed, $error));
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Could not send the backup notification by mail', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function slack($record, bool $failed, ?string $error): void
    {
        $webhook = config('vanguard.notifications.slack.webhook_url');

        if (! config('vanguard.notifications.slack.enabled', false) || empty($webhook)) {
            return;
        }

        $target = $record->tenant_id !== null ? 'tenant '.$record->tenant_id : $record->type;

        $text = $failed
            ? sprintf(':rotating_light: Vanguard backup #%d (%s) failed: %s', $record->id, $target, $error ?? $record->error)
            : sprintf(':white_check_mark: Vanguard backup #%d (%s) completed.', $record->id, $target);

        try {
            // Posted directly rather than through a notification channel: the
            // Slack channel package is not a dependency of this one, and a
            // backup tool should not need one to raise an alarm.
            Http::timeout(10)->post($webhook, ['text' => $text]);
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Could not send the backup notification to Slack', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A failed restore raises the same alarm as a failed backup.
     */
    public function handleRestoreFailure(RestoreFailed $event): void
    {
        if (! config('vanguard.notifications.on_failure', true)) {
            return;
        }

        $to = config('vanguard.notifications.mail.to');

        if (config('vanguard.notifications.mail.enabled', true) && ! empty($to)) {
            try {
                Notification::route('mail', $to)->notify(
                    new RestoreOutcomeNotification($event->record, $event->exception->getMessage()),
                );
            } catch (\Throwable $e) {
                Log::error('[Vanguard] Could not send the restore notification by mail', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->slackForRestore($event->record, $event->exception->getMessage());
    }

    /**
     * Post a failed restore to Slack when a webhook is configured.
     */
    protected function slackForRestore(RestoreRecord $record, string $error): void
    {
        $webhook = config('vanguard.notifications.slack.webhook_url');

        if (! config('vanguard.notifications.slack.enabled', false) || empty($webhook)) {
            return;
        }

        $target = $record->tenant_id !== null ? 'tenant '.$record->tenant_id : $record->type;

        try {
            Http::timeout(10)->post($webhook, [
                'text' => sprintf(':rotating_light: Vanguard restore #%d (%s) failed: %s', $record->id, $target, $error),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Vanguard] Could not send the restore notification to Slack', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
