<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The code that proves the customer holds the new number (spec 017 FR-001).
 * SMS only, to the new number through an on-demand route; never the inbox.
 */
class PhoneChangeCodeNotification extends Notification implements ShouldQueue
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
            ? "رمز تغيير رقم الموبايل في {$app} هو {$this->code}. صالح لمدة {$minutes} دقائق. لا تشاركه مع أحد."
            : "Your {$app} code to change your phone number is {$this->code}. It expires in {$minutes} minutes. Do not share it with anyone.");
    }
}
