<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent from ReviewIdentityDocumentAction on `verify` after commit. */
class CustomerVerifiedNotification extends Notification implements ShouldQueue
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
            ->subject($arabic ? 'تم تفعيل حسابك في '.$appName : 'Your '.$appName.' account is now active')
            ->line($arabic
                ? 'تم التحقق من حسابك بنجاح، ويمكنك الآن تسجيل الدخول واستخدام '.$appName.'.'
                : 'Your account has been verified. You can now sign in and start using '.$appName.'.');
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $appName = (string) config('app.name');

        return SmsMessage::make($arabic
            ? "تم تفعيل حسابك في {$appName}. يمكنك الآن تسجيل الدخول."
            : "Your {$appName} account is now active. You can sign in.");
    }
}
