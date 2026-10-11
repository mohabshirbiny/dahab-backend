<?php

namespace App\Notifications;

use App\Enums\AccountEvent;
use App\Enums\InboxLinkKind;
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

        return (new MailMessage)->subject($this->subject($arabic))->line($this->mailLine($arabic));
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
        return Templates::text("account.{$this->event->value}.mail_subject", $arabic);
    }

    /** The SMS sentence (also the inbox text): the template of the event, filled. */
    public function body(bool $arabic): string
    {
        return Templates::text("account.{$this->event->value}.sms", $arabic, $this->params($arabic));
    }

    /** The email line: its own template when staff wrote one, else the SMS sentence. */
    public function mailLine(bool $arabic): string
    {
        return Templates::mailBody("account.{$this->event->value}", $arabic, $this->params($arabic));
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        return [
            'detail' => $this->detail,
            'phone' => (string) config('dahab-support.phone'),
            'stop' => $this->stopLine($arabic),
            'not_you' => ' '.Templates::text('account.not_you.note', $arabic, ['phone' => (string) config('dahab-support.phone')]),
        ];
    }

    private function stopLine(bool $arabic): string
    {
        $line = '';

        if ($this->cancelledWithdrawals > 0) {
            $line .= ' '.Templates::text('account.stop_cancelled.note', $arabic, ['count' => $this->cancelledWithdrawals]);
        }

        if ($this->pauseUntil !== null) {
            $until = CarbonImmutable::parse($this->pauseUntil)->setTimezone('Africa/Cairo')->format('j M, H:i');
            $line .= ' '.Templates::text('account.stop_paused.note', $arabic, ['until' => $until]);
        }

        return $line;
    }
}
