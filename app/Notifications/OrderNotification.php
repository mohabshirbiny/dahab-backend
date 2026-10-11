<?php

namespace App\Notifications;

use App\Enums\OrderEvent;
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
 * Something happened to an order (spec 012 FR-025, research R20): SMS, plus
 * email when the customer has one, in their language, only after the change
 * commits (sent through NotifyCustomerJob). Never names the other party.
 */
class OrderNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly OrderEvent $event,
        public readonly string $orderRef,
        public readonly string $title,
        public readonly string $titleAr,
        public readonly ?string $amount = null,
        public readonly ?string $deadline = null,
        public readonly ?string $branch = null,
        public readonly ?string $branchAr = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
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
        return $this->deadline === null ? '' : CarbonImmutable::parse($this->deadline)->setTimezone('Africa/Cairo')->format('j M, H:i');
    }

    private function money(): string
    {
        return $this->amount === null ? '' : number_format((float) $this->amount, 2).' EGP';
    }

    public function subject(bool $arabic): string
    {
        return Templates::text("order.{$this->event->value}.mail_subject", $arabic, ['order_ref' => $this->orderRef]);
    }

    /** The SMS sentence (also the inbox text): the template of the event, filled. */
    public function body(bool $arabic): string
    {
        return Templates::text("order.{$this->event->value}.sms", $arabic, $this->params($arabic));
    }

    /** The email line: its own template when staff wrote one, else the SMS sentence. */
    public function mailLine(bool $arabic): string
    {
        return Templates::mailBody("order.{$this->event->value}", $arabic, $this->params($arabic));
    }

    /** @return array<string, scalar|null> what a template of this message may name */
    private function params(bool $arabic): array
    {
        $p = [
            'piece' => $arabic ? $this->titleAr : $this->title,
            'order_ref' => $this->orderRef,
            'branch' => $arabic ? $this->branchAr : $this->branch,
            'deadline' => $this->when(),
            'amount' => $this->money(),
            'code' => $this->code,
            'message' => $this->message,
            'note' => '',
        ];

        // Three events add a sentence when there is something to say (the amount, the window).
        $withNote = match ($this->event) {
            OrderEvent::RETURN_WAITING, OrderEvent::DISPUTE_CANCELLED => $this->amount !== null,
            OrderEvent::COLLECTED => $this->deadline !== null,
            default => false,
        };
        if ($withNote) {
            $p['note'] = ' '.Templates::text("order.{$this->event->value}.note", $arabic, $p);
        }

        return $p;
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        // The collection / return code is never kept in the inbox (staff read it in the
        // Customer file): the order screen shows it to its owner.
        $masked = new self($this->event, $this->orderRef, $this->title, $this->titleAr, $this->amount, $this->deadline,
            $this->branch, $this->branchAr, $this->code === null ? null : '••••••', $this->message);
        $masked->inboxLinkKind = $this->inboxLinkKind;
        $masked->inboxLinkId = $this->inboxLinkId;

        return $masked->inboxMessage($customer, 'order.'.$this->event->value, params: ['order_ref' => $this->orderRef]);
    }
}
