<?php

namespace SoftArtisan\Vanguard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
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
            ->line('Error: '.$this->summarize($this->error))
            ->line('The full error is recorded on the restore history row.')
            ->line('Requested by: '.($this->record->requested_by ?? 'unknown'))
            ->line('The target may be in a partially restored state — check it before retrying.');
    }

    /**
     * Reduce an error to its headline: the first line, capped at 500 chars.
     *
     * Database client errors routinely carry the DB host and user in later
     * lines — e.g. "Access denied for user 'root'@'10.0.0.5'". Until this
     * fix that text stopped at the log file; an email must not be the place
     * it first leaves the server. The complete text stays on the restore
     * record, which sits behind the dashboard's authentication.
     */
    protected function summarize(string $error): string
    {
        $firstLine = explode("\n", trim($error), 2)[0];

        return Str::limit($firstLine, 500);
    }
}
