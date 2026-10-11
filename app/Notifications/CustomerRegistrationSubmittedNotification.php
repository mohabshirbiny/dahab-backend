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

/**
 * Sent at step 6, immediately after the submit transaction commits. The
 * copy MUST say "waiting for verification" — the account is not active
 * yet (docs Part 2 §18.3).
 */
class CustomerRegistrationSubmittedNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

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
        $p = ['app' => (string) config('app.name'), 'display_ref' => $notifiable->display_ref];

        return (new MailMessage)
            ->subject(Templates::text('customer.registration_submitted.mail_subject', $arabic, $p))
            ->line(Templates::text('customer.registration_submitted.mail_body', $arabic, $p))
            ->line(Templates::text('customer.registration_submitted.mail_reference', $arabic, $p));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        /** @var Customer $notifiable */
        $arabic = $notifiable->preferred_lang === 'ar';

        return SmsMessage::make(Templates::text('customer.registration_submitted.sms', $arabic, [
            'app' => (string) config('app.name'),
            'display_ref' => $notifiable->display_ref,
        ]));
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'account.registration_submitted', InboxLinkKind::ACCOUNT);
    }
}
