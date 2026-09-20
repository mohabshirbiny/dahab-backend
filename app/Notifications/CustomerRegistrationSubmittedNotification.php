<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent at step 6, immediately after the submit transaction commits. The
 * copy MUST say "waiting for verification" — the account is not active
 * yet (docs Part 2 §18.3).
 */
class CustomerRegistrationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        $channels = ['sms'];

        if ($notifiable instanceof Customer && filled($notifiable->email)) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $appName = (string) config('app.name');

        return (new MailMessage)
            ->subject($arabic
                ? 'استلمنا طلب تسجيلك في '.$appName
                : 'Your '.$appName.' registration has been submitted')
            ->line($arabic
                ? 'شكرًا لتقديم طلبك. حسابك قيد المراجعة الآن، وسنُخطرك فور اكتمال التحقق.'
                : 'Thank you for submitting your registration. Your account is now awaiting verification and we will notify you as soon as it is complete.')
            ->line($arabic
                ? 'رقم العميل: '.$notifiable->display_ref
                : 'Customer reference: '.$notifiable->display_ref);
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $appName = (string) config('app.name');

        return SmsMessage::make($arabic
            ? "تم استلام طلب تسجيلك في {$appName} وهو قيد المراجعة. رقم العميل: {$notifiable->display_ref}."
            : "Your {$appName} registration has been received and is awaiting verification. Reference: {$notifiable->display_ref}.");
    }
}
