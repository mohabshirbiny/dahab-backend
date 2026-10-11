<?php

namespace App\Notifications;

use App\Enums\ListingDecision;
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

        $mail = (new MailMessage)->subject($this->subject($arabic))->line($this->mailLine($arabic));

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
        return Templates::text("listing.{$this->decision->value}.mail_subject", $arabic);
    }

    private function body(bool $arabic): string
    {
        return Templates::text("listing.{$this->decision->value}.sms", $arabic, $this->params($arabic));
    }

    private function mailLine(bool $arabic): string
    {
        return Templates::mailBody("listing.{$this->decision->value}", $arabic, $this->params($arabic));
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        return ['piece' => $arabic ? $this->titleAr : $this->title];
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'listing.'.$this->decision->value);
    }
}
