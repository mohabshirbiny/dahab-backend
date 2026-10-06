<?php

namespace App\Notifications;

use App\Enums\ListingDecision;
use App\Models\Customer;
use App\Notifications\Concerns\RendersInbox;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\Messages\InboxMessage;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dahab decided on a seller's listing (spec 010 FR-032): approved, changes
 * requested (with the reviewer's message), rejected or taken down (with the
 * reason). SMS, plus email when the customer has one, in their language,
 * sent after the change commits. A failed message never undoes the decision.
 */
class ListingDecisionNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly ListingDecision $decision,
        public readonly string $title,
        public readonly string $titleAr,
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

        $mail = (new MailMessage)->subject($this->subject($arabic))->line($this->body($arabic));

        return $this->message === null ? $mail : $mail->line($this->message);
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        $arabic = $this->arabic($notifiable);
        $text = config('app.name').': '.$this->body($arabic);

        return SmsMessage::make($this->message === null ? $text : $text.' '.$this->message);
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }

    private function subject(bool $arabic): string
    {
        return match ($this->decision) {
            ListingDecision::APPROVED => $arabic ? 'قطعتك اتنشرت' : 'Your piece is live',
            ListingDecision::CHANGES_REQUESTED => $arabic ? 'قطعتك محتاجة تعديل' : 'Your piece needs a change',
            ListingDecision::REJECTED => $arabic ? 'لم نقبل نشر قطعتك' : 'We could not list your piece',
            ListingDecision::TAKEN_DOWN => $arabic ? 'قطعتك اتشالت من السوق' : 'Your piece was taken off the market',
        };
    }

    private function body(bool $arabic): string
    {
        $title = $arabic ? $this->titleAr : $this->title;

        return match ($this->decision) {
            ListingDecision::APPROVED => $arabic
                ? "قطعتك ({$title}) اتراجعت واتنشرت في السوق."
                : "Your piece ({$title}) was approved and is now live on the market.",
            ListingDecision::CHANGES_REQUESTED => $arabic
                ? "قطعتك ({$title}) محتاجة تعديل قبل ما تتنشر:"
                : "Your piece ({$title}) needs a change before it can go live:",
            ListingDecision::REJECTED => $arabic
                ? "لم نقبل نشر قطعتك ({$title}):"
                : "We could not list your piece ({$title}):",
            ListingDecision::TAKEN_DOWN => $arabic
                ? "قطعتك ({$title}) اتشالت من السوق:"
                : "Your piece ({$title}) was taken off the market:",
        };
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'listing.'.$this->decision->value);
    }
}
