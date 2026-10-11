<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
use App\Notifications\Templates\Templates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The person a buyer named to collect a piece (spec 014 FR-018, research R14):
 * one SMS to their phone, which is not a Dahab customer's. Never the
 * collection code — the buyer shares it. Sent on demand after commit, in the
 * buyer's language.
 */
class ProxyNamedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $buyerFirstName,
        public readonly string $title,
        public readonly string $titleAr,
        public readonly ?string $branch,
        public readonly ?string $branchAr,
        public readonly bool $arabic,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return SmsMessage::make(config('app.name').': '.$this->body());
    }

    public function body(): string
    {
        return Templates::text('proxy.named.sms', $this->arabic, [
            'first_name' => $this->buyerFirstName,
            'piece' => $this->arabic ? $this->titleAr : $this->title,
            'branch' => $this->arabic ? $this->branchAr : $this->branch,
        ]);
    }
}
