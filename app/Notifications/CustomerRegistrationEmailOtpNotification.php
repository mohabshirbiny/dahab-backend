<?php

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject($arabic
                ? 'رمز التحقق من '.$appName
                : $appName.' verification code')
            ->line($arabic
                ? 'استخدم الرمز التالي لتأكيد بريدك الإلكتروني في '.$appName.':'
                : 'Use the following code to confirm your email on '.$appName.':')
            ->line('**'.$this->code.'**')
            ->line($arabic
                ? "الرمز صالح لمدة {$minutes} دقائق. لا تشاركه مع أي شخص."
                : "The code is valid for {$minutes} minutes. Do not share it with anyone.");
    }
}
