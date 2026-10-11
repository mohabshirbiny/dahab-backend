<?php

namespace App\Notifications;

use App\Notifications\Templates\Templates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single-use link that proves the customer reads the new address (spec 017
 * FR-010). Email only, to the new address through an on-demand route; never
 * the inbox.
 */
class EmailChangeLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $url,
        private readonly string $lang,
        private readonly int $minutes,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $arabic = $this->lang === 'ar';

        $p = ['minutes' => $this->minutes];

        return (new MailMessage)
            ->subject(Templates::text('auth.email_change_link.mail_subject', $arabic))
            ->line(Templates::text('auth.email_change_link.mail_body', $arabic, $p))
            ->action(Templates::text('auth.email_change_link.mail_action', $arabic), $this->url)
            ->line(Templates::text('auth.email_change_link.mail_ignore', $arabic));
    }
}
