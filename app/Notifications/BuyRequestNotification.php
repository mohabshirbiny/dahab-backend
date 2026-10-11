<?php

namespace App\Notifications;

use App\Enums\BuyRequestEvent;
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
 * Something happened to a buy request (spec 011 FR-024, research R13): SMS,
 * plus email when the customer has one, in their language, only after the
 * change commits (sent through NotifyCustomerJob). Never names the other
 * party: the seller never learns who the buyer is, nor the buyer the seller.
 */
class BuyRequestNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly BuyRequestEvent $event,
        public readonly string $title,
        public readonly string $titleAr,
        public readonly ?string $amount = null,
        public readonly ?string $deadline = null,
        public readonly ?string $orderRef = null,
        public readonly ?string $branch = null,
        public readonly ?string $branchAr = null,
        public readonly ?string $reason = null,
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
        return Templates::text("buy_request.{$this->event->value}.mail_subject", $arabic);
    }

    /** The SMS sentence (also the inbox text): the template of the event, filled. */
    public function body(bool $arabic): string
    {
        return Templates::text("buy_request.{$this->event->value}.sms", $arabic, $this->params($arabic));
    }

    /** The email line: its own template when staff wrote one, else the SMS sentence. */
    public function mailLine(bool $arabic): string
    {
        return Templates::mailBody("buy_request.{$this->event->value}", $arabic, $this->params($arabic));
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
            'reason' => $this->reason,
            'refund' => '',
            'note' => '',
        ];
        $p['refund'] = Templates::text('buy_request.refund.note', $arabic, $p);
        // A cancelled sale mentions the deposit only when one was held.
        $p['note'] = $this->amount === null ? '' : ' '.$p['refund'];

        return $p;
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        $refund = in_array($this->event, [BuyRequestEvent::DECLINED, BuyRequestEvent::NOT_CHOSEN, BuyRequestEvent::EXPIRED,
            BuyRequestEvent::PIECE_WITHDRAWN, BuyRequestEvent::SELLER_SUSPENDED], true);

        return $this->inboxMessage($customer, 'buy_request.'.$this->event->value,
            $refund ? InboxLinkKind::WALLET : InboxLinkKind::NONE, null, array_filter(['order_ref' => $this->orderRef]));
    }
}
