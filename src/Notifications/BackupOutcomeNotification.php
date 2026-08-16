<?php

namespace SoftArtisan\Vanguard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SoftArtisan\Vanguard\Models\BackupRecord;

/**
 * Tells an operator what happened to a backup.
 *
 * A backup that fails without saying so is the whole problem this notification
 * exists for: an installation can go months believing it is protected.
 */
class BackupOutcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly BackupRecord $record,
        public readonly bool $failed,
        public readonly ?string $error = null,
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
        $subject = $this->failed
            ? sprintf('[Vanguard] Backup failed — %s', $this->target())
            : sprintf('[Vanguard] Backup completed — %s', $this->target());

        $message = (new MailMessage)
            ->subject($subject)
            ->line(sprintf('Backup #%d (%s) %s.',
                $this->record->id,
                $this->target(),
                $this->failed ? 'failed' : 'completed',
            ));

        if ($this->failed) {
            $message
                ->error()
                ->line('Error: '.($this->error ?? $this->record->error ?? 'unknown'))
                ->line('No archive was produced for this run.');
        } else {
            $message
                ->line('Size: '.($this->record->file_size_human ?? $this->record->file_size))
                ->line('Destinations: '.implode(', ', array_filter([
                    $this->record->file_path ? 'local' : null,
                    $this->record->remote_path ? 'remote' : null,
                    $this->record->ftp_path ? 'ftp' : null,
                ])) ?: 'none');
        }

        return $message->line('Checked at '.now()->toDateTimeString().'.');
    }

    /**
     * Human-readable name of what was backed up.
     */
    protected function target(): string
    {
        return $this->record->tenant_id !== null
            ? 'tenant '.$this->record->tenant_id
            : $this->record->type;
    }
}
