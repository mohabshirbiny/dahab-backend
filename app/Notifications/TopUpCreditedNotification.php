<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use App\Support\TopUpMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your transfer has landed" (spec 009 FR-026, Clarification Q3): the amount
 * actually credited and the top-up number, by SMS plus email when the
 * customer has one. Dispatched only after the credit commits. Never carries
 * a staff note.
 */
class TopUpCreditedNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    /** @param  string  $amount  the credited amount, a decimal string */
    public function __construct(
        public readonly string $amount,
        public readonly string $number,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        $channels = ['sms'];

        if ($notifiable instanceof Customer && filled($notifiable->email)) {
            array_unshift($channels, 'mail');
        }

        return $this->withInbox($notifiable, $channels);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $amount = TopUpMoney::display($this->amount);

        return (new MailMessage)
            ->subject($arabic ? 'تمت إضافة تحويلك إلى محفظتك' : 'Your transfer is in your wallet')
            ->line($arabic
                ? "أضفنا {$amount} جنيه إلى محفظتك ({$this->number})."
                : "We added {$amount} EGP to your wallet ({$this->number}).");
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $amount = TopUpMoney::display($this->amount);

        return SmsMessage::make($notifiable->preferred_lang === 'ar'
            ? "أضفنا {$amount} جنيه إلى محفظتك في ".config('app.name')." ({$this->number})."
            : "We added {$amount} EGP to your ".config('app.name')." wallet ({$this->number}).");
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'topup.credited', InboxLinkKind::WALLET, null, ['number' => $this->number]);
    }
}
