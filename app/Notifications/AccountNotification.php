<?php

namespace App\Notifications;

use App\Enums\AccountEvent;
use App\Enums\InboxLinkKind;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A security event on the customer's own account (spec 017): a phone, email
 * or password change, a sign-in from a new device, the account closed. SMS,
 * email when there is one, and the inbox, after commit.
 *
 * `$only` narrows the channels: the old number or address of a contact change
 * is told through an on-demand route, the customer row already holding the new
 * one. `$lang` is fixed by the caller, since an on-demand route has no customer.
 */
class AccountNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    /**
     * @param  list<string>|null  $only
     */
    public function __construct(
        public readonly AccountEvent $event,
        public readonly string $lang,
        public readonly ?string $detail = null,
        public readonly ?string $pauseUntil = null,
        public readonly int $cancelledWithdrawals = 0,
        public readonly ?array $only = null,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        $channels = ['sms'];

        if (! $notifiable instanceof Customer || filled($notifiable->email)) {
            array_unshift($channels, 'mail');
        }
        if (! $notifiable instanceof Customer) {
            // An on-demand route: only the channels it names.
            $channels = array_values(array_filter($channels, fn (string $c) => $notifiable->routeNotificationFor($c) !== null));
        }
        if ($this->event !== AccountEvent::ACCOUNT_CLOSED) {
            $channels = $this->withInbox($notifiable, $channels);
        }

        return $this->only === null ? $channels : array_values(array_intersect($channels, $this->only));
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

    public function toInbox(Customer $customer): ?InboxMessage
    {
        if ($this->event === AccountEvent::ACCOUNT_CLOSED) {
            return null;
        }

        return $this->inboxMessage($customer, 'account.'.$this->event->value, InboxLinkKind::ACCOUNT);
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer ? $notifiable->preferred_lang === 'ar' : $this->lang === 'ar';
    }

    public function subject(bool $arabic): string
    {
        return match ($this->event) {
            AccountEvent::PHONE_CHANGED => $arabic ? 'رقم موبايلك اتغير' : 'Your phone number was changed',
            AccountEvent::EMAIL_CHANGED => $arabic ? 'إيميلك اتغير' : 'Your email was changed',
            AccountEvent::PASSWORD_CHANGED => $arabic ? 'كلمة السر اتغيرت' : 'Your password was changed',
            AccountEvent::NEW_DEVICE => $arabic ? 'دخول من جهاز جديد' : 'A sign-in from a new device',
            AccountEvent::ACCOUNT_CLOSED => $arabic ? 'حسابك اتقفل' : 'Your account is closed',
        };
    }

    public function body(bool $arabic): string
    {
        $phone = (string) config('dahab-support.phone');
        $notYou = $arabic
            ? " لو مش انت اللي عملت كده، كلمنا فوراً على {$phone}."
            : " If this was not you, call us now on {$phone}.";
        $stop = $this->stopLine($arabic);

        return match ($this->event) {
            AccountEvent::PHONE_CHANGED => ($arabic
                ? "رقم الموبايل في حسابك على دهب اتغير لـ {$this->detail}."
                : "The phone number on your Dahab account was changed to {$this->detail}.").$stop.$notYou,
            AccountEvent::EMAIL_CHANGED => ($arabic
                ? "الإيميل في حسابك على دهب اتغير لـ {$this->detail}."
                : "The email on your Dahab account was changed to {$this->detail}.").$stop.$notYou,
            AccountEvent::PASSWORD_CHANGED => ($arabic
                ? 'كلمة السر بتاعة حسابك على دهب اتغيرت، والأجهزة التانية خرجت من الحساب.'
                : 'The password of your Dahab account was changed and your other devices were signed out.').$notYou,
            AccountEvent::NEW_DEVICE => ($arabic
                ? "حد دخل حسابك على دهب من جهاز جديد ({$this->detail})."
                : "Someone signed in to your Dahab account from a new device ({$this->detail}).").$notYou,
            AccountEvent::ACCOUNT_CLOSED => $arabic
                ? 'حسابك على دهب اتقفل. السجلات اللي القانون بيطلبها محفوظة للمدة المطلوبة. شكراً إنك كنت معانا.'
                : 'Your Dahab account is closed. Records the law requires are kept for the period it sets. Thank you for using Dahab.',
        };
    }

    private function stopLine(bool $arabic): string
    {
        $line = '';
        if ($this->cancelledWithdrawals > 0) {
            $line .= $arabic
                ? " طلبات السحب اللي ماخرجتش ({$this->cancelledWithdrawals}) اتلغت والفلوس رجعت محفظتك."
                : " Withdrawals that had not left ({$this->cancelledWithdrawals}) were cancelled and the money is back in your wallet.";
        }
        if ($this->pauseUntil !== null) {
            $until = CarbonImmutable::parse($this->pauseUntil)->setTimezone('Africa/Cairo')->format('j M, H:i');
            $line .= $arabic ? " السحب متوقف لحد {$until}." : " Withdrawals are paused until {$until}.";
        }

        return $line;
    }
}
