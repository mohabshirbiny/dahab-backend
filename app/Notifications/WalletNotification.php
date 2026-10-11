<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Enums\WalletEvent;
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
 * Dahab moved money in a customer's wallet outside an order (spec 015 research
 * R14): compensation paid from the Compensation page (a dispute payment keeps
 * using OrderNotification), or a staff correction. SMS plus email, in the
 * customer's language, only after the change commits (NotifyCustomerJob).
 * A correction never carries the staff member's reason. Spec 016: a credit
 * note names the invoice it corrects (`reference`).
 */
class WalletNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly WalletEvent $event,
        public readonly string $amount,
        public readonly ?string $direction = null,
        public readonly ?string $reason = null,
        public readonly ?string $reasonAr = null,
        public readonly ?string $reference = null,
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

        return (new MailMessage)->subject(Templates::text('wallet.mail_subject', $arabic))->line($this->mailLine($arabic));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return SmsMessage::make(config('app.name').': '.$this->body($this->arabic($notifiable)));
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }

    /** The template key of this event (a staff correction has one text per direction). */
    private function base(): string
    {
        return match ($this->event) {
            WalletEvent::COMPENSATION_PAID => 'wallet.compensation_paid',
            WalletEvent::WALLET_ADJUSTED => $this->direction === 'credit' ? 'wallet.adjusted_credit' : 'wallet.adjusted_debit',
            WalletEvent::CREDIT_NOTE_ISSUED => 'wallet.credit_note_issued',
        };
    }

    public function body(bool $arabic): string
    {
        return Templates::text($this->base().'.sms', $arabic, $this->params($arabic));
    }

    public function mailLine(bool $arabic): string
    {
        return Templates::mailBody($this->base(), $arabic, $this->params($arabic));
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        return [
            'amount' => number_format((float) $this->amount, 2).' EGP',
            'reason' => $arabic ? $this->reasonAr : $this->reason,
            'reference' => $this->reference,
        ];
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'wallet.'.$this->event->value, InboxLinkKind::WALLET);
    }
}
