<?php

namespace App\Notifications;

use App\Enums\WalletEvent;
use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
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
class WalletNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $arabic = $this->arabic($notifiable);

        return (new MailMessage)->subject($arabic ? 'محفظتك في دهب' : 'Your Dahab wallet')->line($this->body($arabic));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return SmsMessage::make(config('app.name').': '.$this->body($this->arabic($notifiable)));
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }

    public function body(bool $arabic): string
    {
        $money = number_format((float) $this->amount, 2).' EGP';

        return match ($this->event) {
            WalletEvent::COMPENSATION_PAID => $arabic
                ? "تعويض من دهب ({$money}) وصل محفظتك: {$this->reasonAr}."
                : "Compensation from Dahab ({$money}) is in your wallet: {$this->reason}.",
            WalletEvent::WALLET_ADJUSTED => $this->direction === 'credit'
                ? ($arabic ? "دهب صححت محفظتك وضافت {$money} لرصيدك المتاح." : "Dahab corrected your wallet: {$money} was added to your available balance.")
                : ($arabic ? "دهب صححت محفظتك وخصمت {$money} من رصيدك المتاح." : "Dahab corrected your wallet: {$money} was taken from your available balance."),
            WalletEvent::CREDIT_NOTE_ISSUED => $arabic
                ? "دهب صححت الفاتورة {$this->reference} وضافت {$money} لمحفظتك."
                : "Dahab corrected invoice {$this->reference}: {$money} was added to your wallet.",
        };
    }
}
