<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The code that releases a sign-in from a new device (Part 1 §2.3 — "we send
 * a code to your phone every time you sign in from a new device").
 */
class CustomerLoginOtpNotification extends Notification implements ShouldQueue
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

        return SmsMessage::make($this->lang === 'ar'
            ? "رمز تسجيل الدخول إلى {$app} من جهاز جديد هو {$this->code}. صالح لمدة {$minutes} دقائق. لا تشاركه مع أحد."
            : "Your {$app} sign-in code for a new device is {$this->code}. It expires in {$minutes} minutes. Do not share it with anyone.");
    }
}
