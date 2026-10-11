<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Both founders are emailed when everything is stopped and when it is started again
 * (spec 019 D5). Staff-facing, English; sent to the staff address, after commit.
 */
class FounderAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly bool $stopped,
        public readonly string $actorName,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->stopped ? 'Dahab is stopped' : 'Dahab is running again');

        return $this->stopped
            ? $mail->line($this->actorName.' stopped everything.')
                ->line('Reason: '.$this->reason)
                ->line('No new listings, buy requests or withdrawals are accepted and held withdrawals are not released until it is started again. Money already held stays held, and anything with a locked price finishes normally.')
            : $mail->line($this->actorName.' started everything again.')
                ->line('Note: '.$this->reason);
    }
}
