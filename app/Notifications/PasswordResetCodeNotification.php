<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use App\Notifications\Templates\Templates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The code that lets a customer who forgot the password set a new one (spec 020 D20-1).
 * SMS only, to the phone on file through an on-demand route; never the inbox.
 */
class PasswordResetCodeNotification extends Notification implements ShouldQueue
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
        return ['sms'];
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        $minutes = (int) ceil(((int) config('dahab-auth.otp.ttl_seconds')) / 60);
        $app = (string) config('app.name');

        return SmsMessage::make(Templates::text('auth.password_reset_code.sms', $this->lang === 'ar', [
            'app' => $app,
            'code' => $this->code,
            'minutes' => $minutes,
        ]));
    }
}
