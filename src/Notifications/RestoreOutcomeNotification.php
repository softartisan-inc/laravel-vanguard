<?php

namespace SoftArtisan\Vanguard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SoftArtisan\Vanguard\Models\RestoreRecord;

/**
 * Tells an operator that a restore failed.
 *
 * A restore is attempted when something has already gone wrong; failing it
 * silently leaves the operator believing the recovery worked.
 */
class RestoreOutcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly RestoreRecord $record,
        public readonly string $error,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $target = $this->record->tenant_id !== null
            ? 'tenant '.$this->record->tenant_id
            : $this->record->type;

        return (new MailMessage)
            ->error()
            ->subject(sprintf('[Vanguard] Restore failed — %s', $target))
            ->line(sprintf('Restore #%d of %s failed.', $this->record->id, $target))
            ->line('Error: '.$this->error)
            ->line('Requested by: '.($this->record->requested_by ?? 'unknown'))
            ->line('The target may be in a partially restored state — check it before retrying.');
    }
}
