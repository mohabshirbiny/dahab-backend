<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The phone-verification code for step 1 of registration.
 *
 * Sent to an on-demand notifiable (`Notification::route('sms', $phone)`)
 * because at this point the phone belongs to nobody — there is no customer
 * row, and there will not be one until the final step succeeds.
 */
class CustomerRegistrationOtpNotification extends Notification implements ShouldQueue
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

        return SmsMessage::make($this->lang === 'ar'
            ? "رمز التحقق الخاص بك في {$this->appName()} هو {$this->code}. صالح لمدة {$minutes} دقائق. لا تشاركه مع أحد."
            : "Your {$this->appName()} verification code is {$this->code}. It expires in {$minutes} minutes. Do not share it with anyone.");
    }

    private function appName(): string
    {
        return (string) config('app.name');
    }
}
