<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Enums\TopUpRejectReason;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A transfer notice was rejected (spec 009 FR-026): the plain reason only —
 * the staff note never leaves the Dashboard. SMS plus email when the
 * customer has one, after the change commits.
 */
class TopUpRejectedNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly TopUpRejectReason $reason,
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

        return (new MailMessage)
            ->subject($arabic ? 'لم نتمكن من إضافة تحويلك' : 'We could not add your transfer')
            ->line($arabic
                ? $this->reason->labelAr()." ({$this->number}). لم تتم إضافة أي مبلغ إلى محفظتك."
                : $this->reason->label()." ({$this->number}). Nothing was added to your wallet.");
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        return SmsMessage::make($notifiable->preferred_lang === 'ar'
            ? config('app.name').': '.$this->reason->labelAr()." ({$this->number})."
            : config('app.name').': '.$this->reason->label()." ({$this->number}).");
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'topup.rejected', InboxLinkKind::WALLET, null, ['number' => $this->number]);
    }
}
