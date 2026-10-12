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
 * "Your friend completed a sale" (spec 023 R22, R27): the inviter earned a commission credit.
 * SMS, plus email when the customer has one, and the inbox; the amount is the credit, never money.
 */
class ReferralRewardNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(public readonly string $amount) {}

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
        $ar = $this->arabic($notifiable);

        return (new MailMessage)->subject(Templates::text('referral.reward_earned.mail_subject', $ar))
            ->line(Templates::mailBody('referral.reward_earned', $ar, $this->params()));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return SmsMessage::make(config('app.name').': '.Templates::text('referral.reward_earned.sms', $this->arabic($notifiable), $this->params()));
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        // The Invite screen shows what was earned.
        return $this->inboxMessage($customer, 'referral.reward_earned', InboxLinkKind::ACCOUNT, null, ['amount' => $this->amount]);
    }

    /** @return array<string, scalar> */
    private function params(): array
    {
        return ['amount' => number_format((float) $this->amount, 2).' EGP'];
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }
}
