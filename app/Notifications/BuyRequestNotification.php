<?php

namespace App\Notifications;

use App\Enums\BuyRequestEvent;
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
        return $this->deadline === null ? '' : CarbonImmutable::parse($this->deadline)->setTimezone('Africa/Cairo')->format('j M, H:i');
    }

    private function money(): string
    {
        return $this->amount === null ? '' : number_format((float) $this->amount, 2).' EGP';
    }

    public function subject(bool $arabic): string
    {
        return match ($this->event) {
            BuyRequestEvent::NEW_REQUEST => $arabic ? 'طلب شراء جديد على قطعتك' : 'A buy request on your piece',
            BuyRequestEvent::ACCEPTED => $arabic ? 'البايع قبل طلبك' : 'The seller accepted your request',
            BuyRequestEvent::DECLINED => $arabic ? 'البايع رفض طلبك' : 'The seller declined your request',
            BuyRequestEvent::NOT_CHOSEN => $arabic ? 'البايع اختار مشتري تاني' : 'The seller chose another buyer',
            BuyRequestEvent::EXPIRED => $arabic ? 'البايع مردش في الميعاد' : 'The seller did not reply in time',
            BuyRequestEvent::PIECE_WITHDRAWN => $arabic ? 'القطعة اتشالت من السوق' : 'The piece was taken off the market',
            BuyRequestEvent::SELLER_SUSPENDED => $arabic ? 'القطعة مش متاحة دلوقتي' : 'The piece is no longer available',
            BuyRequestEvent::FREE_AGAIN => $arabic ? 'القطعة رجعت متاحة' : 'The piece is free again',
            BuyRequestEvent::ORDER_CANCELLED => $arabic ? 'دهب لغت البيعة' : 'Dahab cancelled the sale',
        };
    }

    public function body(bool $arabic): string
    {
        $t = $arabic ? $this->titleAr : $this->title;
        $refund = $arabic ? "عربونك ({$this->money()}) رجع لمحفظتك." : "Your deposit ({$this->money()}) is back in your wallet.";

        return match ($this->event) {
            BuyRequestEvent::NEW_REQUEST => $arabic
                ? "فيه طلب شراء على قطعتك ({$t}). رد قبل {$this->when()}."
                : "There is a buy request on your piece ({$t}). Reply before {$this->when()}.",
            BuyRequestEvent::ACCEPTED => $arabic
                ? "البايع قبل طلبك على ({$t}). رقم الطلب {$this->orderRef}، الفرع {$this->branchAr}. البايع لازم يوصل الفرع قبل {$this->when()}. عربونك لسه محجوز."
                : "The seller accepted your request on ({$t}). Order {$this->orderRef}, branch {$this->branch}. The seller must reach the branch by {$this->when()}. Your deposit stays held.",
            BuyRequestEvent::DECLINED => $arabic
                ? "البايع رفض طلبك على ({$t}). {$refund}"
                : "The seller declined your request on ({$t}). {$refund}",
            BuyRequestEvent::NOT_CHOSEN => $arabic
                ? "البايع قبل مشتري قبلك على ({$t}). {$refund}"
                : "The seller took a buyer ahead of you on ({$t}). {$refund}",
            BuyRequestEvent::EXPIRED => $arabic
                ? "البايع مردش على طلبك على ({$t}) في الميعاد. {$refund}"
                : "The seller did not reply to your request on ({$t}) in time. {$refund}",
            BuyRequestEvent::PIECE_WITHDRAWN => $arabic
                ? "القطعة ({$t}) اتشالت من السوق. {$refund}"
                : "The piece ({$t}) was taken off the market. {$refund}",
            BuyRequestEvent::SELLER_SUSPENDED => $arabic
                ? "القطعة ({$t}) مش متاحة دلوقتي. {$refund}"
                : "The piece ({$t}) is no longer available. {$refund}",
            BuyRequestEvent::FREE_AGAIN => $arabic
                ? "القطعة ({$t}) رجعت في السوق ومفيش حد في الطابور. تقدر تبعت طلب جديد."
                : "The piece ({$t}) is back on the market with nobody in line. You can send a new request.",
            BuyRequestEvent::ORDER_CANCELLED => $arabic
                ? "دهب لغت البيعة {$this->orderRef} على ({$t}): {$this->reason}".($this->amount === null ? '' : " {$refund}")
                : "Dahab cancelled sale {$this->orderRef} on ({$t}): {$this->reason}".($this->amount === null ? '' : " {$refund}"),
        };
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        $refund = in_array($this->event, [BuyRequestEvent::DECLINED, BuyRequestEvent::NOT_CHOSEN, BuyRequestEvent::EXPIRED,
            BuyRequestEvent::PIECE_WITHDRAWN, BuyRequestEvent::SELLER_SUSPENDED], true);

        return $this->inboxMessage($customer, 'buy_request.'.$this->event->value,
            $refund ? InboxLinkKind::WALLET : InboxLinkKind::NONE, null, array_filter(['order_ref' => $this->orderRef]));
    }
}
