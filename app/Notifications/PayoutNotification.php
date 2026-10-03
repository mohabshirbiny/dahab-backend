<?php

namespace App\Notifications;

use App\Enums\PayoutEvent;
use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
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
class PayoutNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        return $channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $arabic = $this->arabic($notifiable);

        return (new MailMessage)->subject($this->subject($arabic))->line($this->body($arabic));
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
        return match ($this->event) {
            PayoutEvent::ACCOUNT_ADDED, PayoutEvent::ACCOUNT_VERIFIED, PayoutEvent::ACCOUNT_REFUSED,
            PayoutEvent::ACCOUNT_IN_USE, PayoutEvent::ACCOUNT_REMOVED => $arabic ? 'حساب استلام الفلوس' : 'Your payout account',
            default => $arabic ? 'سحب الفلوس' : 'Your withdrawal',
        };
    }

    public function body(bool $arabic): string
    {
        $a = (string) $this->account;
        $n = (string) $this->number;
        $money = $this->money();
        $when = $this->when();

        return match ($this->event) {
            PayoutEvent::ACCOUNT_ADDED => $arabic
                ? "اتضاف حساب استلام جديد ({$a}) وهنراجع الاسم مع هويتك. لو مش انت اللي ضفته كلمنا فوراً."
                : "A new payout account ({$a}) was added and will be checked against your ID. If this was not you, contact us at once.",
            PayoutEvent::ACCOUNT_VERIFIED => $arabic
                ? "حساب الاستلام ({$a}) اتراجع واتأكد."
                : "Your payout account ({$a}) was checked and confirmed.",
            PayoutEvent::ACCOUNT_REFUSED => $arabic
                ? "مقدرناش نقبل حساب الاستلام ({$a}): {$this->reasonAr}. ضيف حساب تاني باسمك."
                : "We could not accept the payout account ({$a}): {$this->reason}. Add another account in your name.",
            PayoutEvent::ACCOUNT_IN_USE => $arabic
                ? "الفلوس هتتسحب دلوقتي لحساب ({$a})."
                    .($this->until !== null ? " السحب متوقف لحد {$when}." : '')
                    .' لو مش انت اللي غيرته كلمنا فوراً.'
                : "Withdrawals now go to ({$a})."
                    .($this->until !== null ? " New withdrawals are paused until {$when}." : '')
                    .' If this was not you, contact us at once.',
            PayoutEvent::ACCOUNT_REMOVED => $arabic
                ? "حساب الاستلام ({$a}) اتشال."
                : "The payout account ({$a}) was removed.",
            PayoutEvent::WITHDRAWAL_HELD => $arabic
                ? "السحب {$n} ({$money}) متوقف للمراجعة: {$this->message}"
                : "Withdrawal {$n} ({$money}) is on hold: {$this->message}",
            PayoutEvent::WITHDRAWAL_RELEASED => $arabic
                ? "السحب {$n} ({$money}) اتبعت لحساب ({$a})."
                : "Withdrawal {$n} ({$money}) was sent to ({$a}).",
            PayoutEvent::WITHDRAWAL_REJECTED => $arabic
                ? "السحب {$n} ({$money}) اترفض: {$this->reasonAr}. الفلوس رجعت لرصيدك المتاح."
                : "Withdrawal {$n} ({$money}) was rejected: {$this->reason}. The money is back in your available balance.",
            PayoutEvent::WITHDRAWALS_CANCELLED_BY_CHANGE => $arabic
                ? "عشان حساب الاستلام اتغير، اتلغى {$this->cancelledCount} سحب لسه ما طلعش والفلوس رجعت لرصيدك المتاح."
                : "Because your payout account changed, {$this->cancelledCount} withdrawal(s) that had not left were cancelled and the money is back in your available balance.",
            PayoutEvent::WITHDRAWALS_OPEN => $arabic
                ? 'فترة إيقاف السحب خلصت. تقدر تسحب تاني.'
                : 'The withdrawal pause has ended. You can withdraw again.',
        };
    }
}
