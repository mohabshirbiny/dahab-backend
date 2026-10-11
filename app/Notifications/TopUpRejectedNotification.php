<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Enums\TopUpRejectReason;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use App\Notifications\Templates\Templates;
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
            ->subject(Templates::text('topup.rejected.mail_subject', $arabic))
            ->line(Templates::mailBody('topup.rejected', $arabic, $this->params($arabic)));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';

        return SmsMessage::make(config('app.name').': '.Templates::text('topup.rejected.sms', $arabic, $this->params($arabic)));
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        return ['reason' => $arabic ? $this->reason->labelAr() : $this->reason->label(), 'number' => $this->number];
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'topup.rejected', InboxLinkKind::WALLET, null, ['number' => $this->number]);
    }
}
