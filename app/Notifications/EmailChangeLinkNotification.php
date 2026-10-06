<?php

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject($arabic ? 'أكد إيميلك الجديد في دهب' : 'Confirm your new Dahab email')
            ->line($arabic
                ? "افتح اللينك ده عشان تأكد إن الإيميل ده بتاعك. صالح لمدة {$this->minutes} دقيقة ولمرة واحدة."
                : "Open this link to confirm this address is yours. It works once, for {$this->minutes} minutes.")
            ->action($arabic ? 'أكد الإيميل' : 'Confirm email', $this->url)
            ->line($arabic ? 'لو مش انت اللي طلبت ده، تجاهل الرسالة.' : 'If you did not ask for this, ignore this email.');
    }
}
