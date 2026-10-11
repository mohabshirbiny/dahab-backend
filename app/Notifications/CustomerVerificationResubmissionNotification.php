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

/** Sent from ReviewIdentityDocumentAction on `request_resubmission` after commit. */
class CustomerVerificationResubmissionNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

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

        return $this->withInbox($notifiable, $channels);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';
        $p = ['app' => (string) config('app.name')];

        $message = (new MailMessage)
            ->subject(Templates::text('customer.resubmission.mail_subject', $arabic, $p))
            ->line(Templates::text('customer.resubmission.mail_body', $arabic, $p));

        foreach ($this->reasons as $reason) {
            $message->line('• '.$reason);
        }

        if ($this->note !== null) {
            $message->line(Templates::text('customer.resubmission.mail_note', $arabic).$this->note);
        }

        return $message->line(Templates::text('customer.resubmission.mail_footer', $arabic));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';

        return SmsMessage::make(Templates::text('customer.resubmission.sms', $arabic, [
            'app' => (string) config('app.name'),
            'reasons' => implode(', ', $this->reasons),
        ]));
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'account.resubmission_requested', InboxLinkKind::ACCOUNT);
    }
}
