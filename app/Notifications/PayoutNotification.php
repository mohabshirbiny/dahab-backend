<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Enums\PayoutEvent;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use App\Notifications\Templates\Templates;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something happened to a payout account or a withdrawal (spec 013 FR-018,
 * research R11): SMS plus email, in the customer's language, only after the
 * change commits (sent through NotifyCustomerJob). Carries only the masked
 * account ("CIB •••• 4417"), never the full number and never a staff note.
 *
 * `account_added` and `account_in_use` go to the customer even though they
 * acted themselves: terms §9.3, "you are told on your current number and email
 * when the account changes" — a hijacked session must not change it silently.
 */
class PayoutNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly PayoutEvent $event,
        public readonly ?string $account = null,
        public readonly ?string $amount = null,
        public readonly ?string $number = null,
        public readonly ?string $until = null,
        public readonly ?string $reason = null,
        public readonly ?string $reasonAr = null,
        public readonly ?string $message = null,
        public readonly int $cancelledCount = 0,
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
        $arabic = $this->arabic($notifiable);

        return (new MailMessage)->subject($this->subject($arabic))->line($this->mailLine($arabic));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return SmsMessage::make(config('app.name').': '.$this->body($this->arabic($notifiable)));
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }

    private function when(): string
    {
        return $this->until === null ? '' : CarbonImmutable::parse($this->until)->setTimezone('Africa/Cairo')->format('j M, H:i');
    }

    private function money(): string
    {
        return $this->amount === null ? '' : number_format((float) $this->amount, 2).' EGP';
    }

    public function subject(bool $arabic): string
    {
        // The five payout-account events share one subject, the withdrawals the other.
        return Templates::text("payout.{$this->event->value}.mail_subject", $arabic);
    }

    /** The SMS sentence (also the inbox text): the template of the event, filled. */
    public function body(bool $arabic): string
    {
        return Templates::text("payout.{$this->event->value}.sms", $arabic, $this->params($arabic));
    }

    /** The email line: its own template when staff wrote one, else the SMS sentence. */
    public function mailLine(bool $arabic): string
    {
        return Templates::mailBody("payout.{$this->event->value}", $arabic, $this->params($arabic));
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        $p = [
            'account' => (string) $this->account,
            'amount' => $this->money(),
            'number' => (string) $this->number,
            'until' => $this->when(),
            'reason' => $arabic ? $this->reasonAr : $this->reason,
            'message' => $this->message,
            'count' => $this->cancelledCount,
            'paused' => '',
        ];

        if ($this->event === PayoutEvent::ACCOUNT_IN_USE && $this->until !== null) {
            $p['paused'] = ' '.Templates::text('payout.account_in_use.paused.note', $arabic, $p);
        }

        return $p;
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        $money = in_array($this->event, [PayoutEvent::WITHDRAWAL_RELEASED, PayoutEvent::WITHDRAWAL_REJECTED,
            PayoutEvent::WITHDRAWAL_HELD, PayoutEvent::WITHDRAWALS_CANCELLED_BY_CHANGE], true);

        return $this->inboxMessage($customer, 'payout.'.$this->event->value,
            $money ? InboxLinkKind::WALLET : InboxLinkKind::ACCOUNT, null, array_filter(['number' => $this->number]));
    }
}
