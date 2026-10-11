<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use App\Notifications\Templates\Templates;
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

        return SmsMessage::make(Templates::text('auth.registration_otp.sms', $this->lang === 'ar', [
            'app' => $this->appName(),
            'code' => $this->code,
            'minutes' => $minutes,
        ]));
    }

    private function appName(): string
    {
        return (string) config('app.name');
    }
}
