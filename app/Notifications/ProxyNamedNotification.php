<?php

namespace App\Notifications;

use App\Notifications\Messages\SmsMessage;
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
        $n = $this->buyerFirstName;

        return $this->arabic
            ? "{$n} اختارك تستلم ({$this->titleAr}) بالنيابة عنه من IGI {$this->branchAr}. هات بطاقتك، وهو هيبعتلك كود الاستلام."
            : "{$n} named you to collect ({$this->title}) for them at IGI {$this->branch}. Bring your ID; they will give you the collection code.";
    }
}
