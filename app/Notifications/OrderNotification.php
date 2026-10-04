<?php

namespace App\Notifications;

use App\Enums\OrderEvent;
use App\Models\Customer;
use App\Notifications\Messages\SmsMessage;
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
class OrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return $this->deadline === null ? '' : CarbonImmutable::parse($this->deadline)->setTimezone('Africa/Cairo')->format('j M, H:i');
    }

    private function money(): string
    {
        return $this->amount === null ? '' : number_format((float) $this->amount, 2).' EGP';
    }

    public function subject(bool $arabic): string
    {
        $r = $this->orderRef;

        return $arabic ? "طلب {$r}" : "Order {$r}";
    }

    public function body(bool $arabic): string
    {
        $t = $arabic ? $this->titleAr : $this->title;
        $r = $this->orderRef;
        $b = $arabic ? $this->branchAr : $this->branch;
        $when = $this->when();
        $money = $this->money();

        return match ($this->event) {
            OrderEvent::RECEIVED => $arabic
                ? "القطعة ({$t}) وصلت الفرع وهتتفحص. طلب {$r}."
                : "The piece ({$t}) reached the branch and will be inspected. Order {$r}.",
            OrderEvent::SELLER_CANCELLED => $arabic
                ? "البايع لغى البيعة ({$t}). عربونك ({$money}) رجع لمحفظتك. طلب {$r}."
                : "The seller cancelled the sale of ({$t}). Your deposit ({$money}) is back in your wallet. Order {$r}.",
            OrderEvent::DEADLINE_MISSED => $arabic
                ? "القطعة ({$t}) موصلتش الفرع في الميعاد فالبيعة اتلغت. طلب {$r}."
                : "The piece ({$t}) did not reach the branch in time, so the sale is cancelled. Order {$r}.",
            OrderEvent::BRANCH_CHANGED => $arabic
                ? "فرع الطلب {$r} اتغير لـ {$b}. الميعاد: {$when}."
                : "The branch for order {$r} is now {$b}. Deadline: {$when}.",
            OrderEvent::DEADLINE_EXTENDED => $arabic
                ? "ميعاد الطلب {$r} اتمد لـ {$when}."
                : "The deadline for order {$r} is now {$when}.",
            OrderEvent::RESULT_PASSED => $arabic
                ? "الفحص عدى ({$t}). السعر النهائي {$money}. طلب {$r}."
                : "The piece ({$t}) passed inspection. Final price {$money}. Order {$r}.",
            OrderEvent::RESULT_ADJUST => $arabic
                ? "الفحص لقى فرق في ({$t}). السعر الجديد {$money}. اقبل أو ارفض قبل {$when}. طلب {$r}."
                : "Inspection found a difference on ({$t}). The new price is {$money}. Accept or decline before {$when}. Order {$r}.",
            OrderEvent::RESULT_BUYER_DECIDING => $arabic
                ? "الفحص لقى فرق في ({$t}) والمشتري بيقرر دلوقتي. قطعتك في أمان في الفرع. طلب {$r}."
                : "Inspection found a difference on ({$t}) and the buyer is deciding now. Your piece is safe at the branch. Order {$r}.",
            OrderEvent::RESULT_REGRADE_PENDING => $arabic
                ? "الفحص لقى درجة الحجر أقل في ({$t}). هنبلغك بالسعر الجديد. طلب {$r}."
                : "Inspection graded the stone of ({$t}) lower. We will tell you the new price. Order {$r}.",
            OrderEvent::PRICE_PROPOSED => $arabic
                ? "السعر الجديد لـ ({$t}) هو {$money}. اقبل أو ارفض قبل {$when}. طلب {$r}."
                : "The new price for ({$t}) is {$money}. Accept or decline before {$when}. Order {$r}.",
            OrderEvent::RESULT_CANCELLED => $arabic
                ? "القطعة ({$t}) مطابقتش الإعلان في الفحص فالبيعة اتلغت. طلب {$r}."
                : "The piece ({$t}) did not match its listing at inspection, so the sale is cancelled. Order {$r}.",
            OrderEvent::DECISION_ACCEPTED => $arabic
                ? "المشتري قبل السعر الجديد لـ ({$t}). طلب {$r}."
                : "The buyer accepted the new price for ({$t}). Order {$r}.",
            OrderEvent::DECISION_DECLINED => $arabic
                ? "المشتري مقبلش السعر الجديد لـ ({$t}) فالبيعة اتلغت. القطعة مستنياك في الفرع. طلب {$r}."
                : "The buyer did not accept the new price for ({$t}), so the sale is cancelled. Your piece is waiting at the branch. Order {$r}.",
            OrderEvent::DECISION_EXPIRED => $arabic
                ? "مردتش على السعر الجديد لـ ({$t}) في الميعاد فالبيعة اتلغت. عربونك ({$money}) رجع لمحفظتك. طلب {$r}."
                : "You did not answer the new price for ({$t}) in time, so the sale is cancelled. Your deposit ({$money}) is back in your wallet. Order {$r}.",
            OrderEvent::PAID => $arabic
                ? "المشتري دفع. {$money} وصلوا محفظتك عن ({$t}). طلب {$r}."
                : "The buyer paid. {$money} reached your wallet for ({$t}). Order {$r}.",
            OrderEvent::COLLECTION_CODE => $arabic
                ? "اتدفع بالكامل. كود الاستلام {$this->code}. استلم ({$t}) من {$b} قبل {$when} ومعاك بطاقتك. طلب {$r}."
                : "Paid in full. Your collection code is {$this->code}. Collect ({$t}) at {$b} before {$when} with your ID. Order {$r}.",
            OrderEvent::FORFEITED => $arabic
                ? "الباقي ما اتدفعش في الميعاد فالبيعة اتلغت ({$t}). طلب {$r}."
                : "The balance was not paid in time, so the sale of ({$t}) is cancelled. Order {$r}.",
            OrderEvent::RETURN_WAITING => ($arabic
                ? "القطعة ({$t}) مستنياك في {$b}. كود الاستلام {$this->code}، قبل {$when}."
                : "Your piece ({$t}) is waiting at {$b}. Collection code {$this->code}, before {$when}.")
                .($this->amount === null ? '' : ($arabic
                    ? " نصيبك من العربون ({$money}) وصل محفظتك كتعويض."
                    : " Your share of the buyer's deposit ({$money}) is in your wallet as compensation.")),
            OrderEvent::RETURN_WINDOW_PASSED => $arabic
                ? "ميعاد استلام القطعة ({$t}) عدى. كلمنا. طلب {$r}."
                : "The window to collect your piece ({$t}) has passed. Please contact us. Order {$r}.",
            OrderEvent::COLLECTED => $arabic
                ? "القطعة ({$t}) اتسلمت. طلب {$r}."
                : "The piece ({$t}) has been collected. Order {$r}.",
            OrderEvent::COLLECTION_WINDOW_PASSED => $arabic
                ? "ميعاد الاستلام عدى والقطعة ({$t}) لسه في الفرع. كلمنا. طلب {$r}."
                : "The collection window has passed and the piece ({$t}) is still at the branch. Please contact us. Order {$r}.",
            OrderEvent::REACH_REMINDER => $arabic
                ? "فاضل وقت قليل توصل ({$t}) لـ {$b}. الميعاد {$when}. طلب {$r}."
                : "Little time is left to bring ({$t}) to {$b}. Deadline {$when}. Order {$r}.",
            OrderEvent::BALANCE_REMINDER => $arabic
                ? "ادفع الباقي ({$money}) لـ ({$t}) قبل {$when}. طلب {$r}."
                : "Pay the balance ({$money}) for ({$t}) before {$when}. Order {$r}.",
            // Spec 014. The other party is never told what the dispute says.
            OrderEvent::DISPUTE_OPENED => $arabic
                ? "الطلب {$r} ({$t}) متوقف لحد ما دهب تراجع مشكلة. مفيش فلوس بتتحرك ومفيش ميعاد بيجري عليك."
                : "Order {$r} ({$t}) is on hold while Dahab looks into a problem. No money moves and no deadline runs against you.",
            OrderEvent::DISPUTE_RESOLVED => $arabic
                ? "رد دهب على المشكلة في الطلب {$r}: {$this->message}"
                : "Dahab's reply on order {$r}: {$this->message}",
            OrderEvent::DISPUTE_RESUMED => $arabic
                ? "الطلب {$r} ({$t}) رجع يمشي بعد المراجعة."
                : "Order {$r} ({$t}) is moving again after Dahab's review.",
            OrderEvent::DISPUTE_CANCELLED => ($arabic
                ? "البيعة ({$t}) اتلغت بعد مراجعة دهب. طلب {$r}."
                : "The sale of ({$t}) is cancelled after Dahab's review. Order {$r}.")
                .($this->amount === null ? '' : ($arabic
                    ? " عربونك ({$money}) رجع لمحفظتك."
                    : " Your deposit ({$money}) is back in your wallet.")),
            OrderEvent::COMPENSATION_PAID => $arabic
                ? "تعويض من دهب ({$money}) وصل محفظتك. طلب {$r}."
                : "Compensation from Dahab ({$money}) is in your wallet. Order {$r}.",
            OrderEvent::EXTENSION_REFUSED => $arabic
                ? "طلب الوقت الإضافي للطلب {$r} ماتقبلش. الميعاد زي ما هو: {$when}. {$this->message}"
                : "Your request for more time on order {$r} was not accepted. The deadline stays {$when}. {$this->message}",
            OrderEvent::PROXY_NAMED => $arabic
                ? "{$this->message} يقدر يستلم ({$t}) بالنيابة عنك من {$b}. ابعتله كود الاستلام بنفسك. طلب {$r}."
                : "{$this->message} can now collect ({$t}) for you at {$b}. Share your collection code with them yourself. Order {$r}.",
        };
    }
}
