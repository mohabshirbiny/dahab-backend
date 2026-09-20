<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\SmsMessage;
use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * The `sms` notification channel. Registered in AppServiceProvider, so any
 * notification may list 'sms' in its `via()` and implement `toSms()`.
 *
 * The recipient comes from the notifiable's `routeNotificationFor('sms')`,
 * which covers both a Customer model and an on-demand
 * `Notification::route('sms', $phone)` — the registration OTP goes to a phone
 * that has no customer row yet.
 */
final class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($to) || $to === '') {
            return;
        }

        /** @var SmsMessage|string $message */
        $message = $notification->toSms($notifiable);

        $this->sender->send($to, $message instanceof SmsMessage ? $message->content : $message);
    }
}
