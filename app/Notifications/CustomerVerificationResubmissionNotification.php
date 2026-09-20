<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent from ReviewIdentityDocumentAction on `request_resubmission` after commit. */
class CustomerVerificationResubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly ?string $note,
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
            ->subject($arabic ? $appName.' — نحتاج نسخة أخرى من الوثيقة' : $appName.' — we need another copy of your document')
            ->line($arabic
                ? 'راجعنا الوثيقة التي أرسلتها ونحتاج منك إعادة إرسالها. الأسباب:'
                : 'We reviewed your submitted document and need you to resubmit it. Reasons:');

        foreach ($this->reasons as $reason) {
            $message->line('• '.$reason);
        }

        if ($this->note !== null) {
            $message->line(($arabic ? 'ملاحظة من المراجع: ' : 'Reviewer note: ').$this->note);
        }

        return $message->line($arabic
            ? 'يرجى تسجيل الدخول ورفع نسخة جديدة عند جاهزيتها.'
            : 'Please sign in and upload a new copy when ready.');
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $appName = (string) config('app.name');
        $reasons = implode(', ', $this->reasons);

        return SmsMessage::make($arabic
            ? "{$appName}: نحتاج إعادة إرسال وثيقتك ({$reasons})."
            : "{$appName}: please resubmit your identity document ({$reasons}).");
    }
}
