<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent from ReviewIdentityDocumentAction on `reject` after commit. */
class CustomerVerificationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly ?string $note,
        public readonly array $reasons,
    ) {}

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

        $message = (new MailMessage)
            ->subject($arabic ? $appName.' — تم رفض طلب التسجيل' : $appName.' — your registration was rejected')
            ->line($arabic ? 'للأسف لم نتمكن من تفعيل حسابك.' : 'We were unable to approve your registration.');

        foreach ($this->reasons as $reason) {
            $message->line('• '.$reason);
        }

        if ($this->note !== null) {
            $message->line(($arabic ? 'السبب: ' : 'Reason: ').$this->note);
        }

        return $message;
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $appName = (string) config('app.name');

        return SmsMessage::make($arabic
            ? "{$appName}: تم رفض طلب التسجيل. راجع بريدك للتفاصيل."
            : "{$appName}: your registration was rejected. Check your inbox for details.");
    }
}
