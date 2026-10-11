<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
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

/** Sent from ReviewIdentityDocumentAction on `reject` after commit. */
class CustomerVerificationRejectedNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

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

        return $this->withInbox($notifiable, $channels);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $p = ['app' => (string) config('app.name')];

        $message = (new MailMessage)
            ->subject(Templates::text('customer.verification_rejected.mail_subject', $arabic, $p))
            ->line(Templates::text('customer.verification_rejected.mail_body', $arabic, $p));

        foreach ($this->reasons as $reason) {
            $message->line('• '.$reason);
        }

        if ($this->note !== null) {
            $message->line(Templates::text('customer.verification_rejected.mail_reason', $arabic).$this->note);
        }

        return $message;
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';

        return SmsMessage::make(Templates::text('customer.verification_rejected.sms', $arabic, ['app' => (string) config('app.name')]));
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'account.verification_rejected', InboxLinkKind::ACCOUNT);
    }
}
