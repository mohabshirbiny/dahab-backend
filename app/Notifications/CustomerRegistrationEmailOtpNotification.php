<?php

namespace App\Notifications;

use App\Notifications\Templates\Templates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 3 → 4: the email verification OTP. Sent to an on-demand notifiable
 * (`Notification::route('mail', $email)`) — the customer row does not exist
 * yet, so we address the raw email instead.
 */
class CustomerRegistrationEmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $code,
        private readonly string $lang,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $minutes = (int) ceil(((int) config('dahab-auth.otp.ttl_seconds')) / 60);
        $arabic = $this->lang === 'ar';
        $appName = (string) config('app.name');

        $p = ['app' => $appName, 'minutes' => $minutes];

        return (new MailMessage)
            ->subject(Templates::text('auth.registration_email_otp.mail_subject', $arabic, $p))
            ->line(Templates::text('auth.registration_email_otp.mail_intro', $arabic, $p))
            ->line('**'.$this->code.'**')
            ->line(Templates::text('auth.registration_email_otp.mail_expiry', $arabic, $p));
    }
}
