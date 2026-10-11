<?php

namespace App\Notifications;

use App\Enums\InboxLinkKind;
use App\Enums\PieceCategory;
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
 * A category was paused (spec 019 US7 FR-071): told only to the sellers with a piece in it.
 * The words are what staff wrote for customers, in the customer's language; nothing about
 * who paused it or why. SMS, email when there is one, and the inbox.
 */
class CategoryNotification extends Notification implements InboxNotification, ShouldQueue
{
    use Queueable, RendersInbox;

    public int $tries = 3;

    public function __construct(
        public readonly PieceCategory $category,
        public readonly string $messageEn,
        public readonly string $messageAr,
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

        return (new MailMessage)
            ->subject(Templates::text('category.paused.mail_subject', $arabic, $this->params($arabic)))
            ->line(Templates::mailBody('category.paused', $arabic, $this->params($arabic)));
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        $arabic = $this->arabic($notifiable);

        return SmsMessage::make(config('app.name').': '.Templates::text('category.paused.sms', $arabic, $this->params($arabic)));
    }

    public function toInbox(Customer $customer): ?InboxMessage
    {
        return $this->inboxMessage($customer, 'category.paused', InboxLinkKind::NONE, null, ['category' => $this->category->value]);
    }

    private function arabic(mixed $notifiable): bool
    {
        return $notifiable instanceof Customer && $notifiable->preferred_lang === 'ar';
    }

    /** @return array<string, scalar|null> */
    private function params(bool $arabic): array
    {
        return [
            'category' => $this->category->label($arabic),
            'message' => $arabic ? ($this->messageAr !== '' ? $this->messageAr : $this->messageEn) : ($this->messageEn !== '' ? $this->messageEn : $this->messageAr),
        ];
    }
}
