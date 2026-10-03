<?php

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email second-check (Part 1 §2.4; spec 013 research R5): mail only, to
 * the customer's confirmed address. The link opens a Customer App page that
 * confirms only when the customer taps Confirm, so a mail scanner opening it
 * changes nothing.
 */
class WithdrawalConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $link,
        public readonly string $amount,
        public readonly string $account,
        public readonly int $minutes,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $money = number_format((float) $this->amount, 2).' EGP';

        if ($notifiable instanceof Customer && $notifiable->preferred_lang === 'ar') {
            return (new MailMessage)
                ->subject('أكد السحب')
                ->line("طلبت تسحب {$money} لحساب ({$this->account}).")
                ->action('أكد السحب', $this->link)
                ->line("اللينك شغال {$this->minutes} دقيقة ومرة واحدة بس. لو مش انت اللي طلبت، متفتحوش وكلمنا.");
        }

        return (new MailMessage)
            ->subject('Confirm your withdrawal')
            ->line("You asked to withdraw {$money} to ({$this->account}).")
            ->action('Confirm the withdrawal', $this->link)
            ->line("The link works once, for {$this->minutes} minutes. If this was not you, do not open it and contact us.");
    }
}
