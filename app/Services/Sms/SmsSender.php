<?php

namespace App\Services\Sms;

/**
 * One outbound SMS. Implementations are responsible for delivery only — the
 * decision to send, and any retry policy, belongs to the queued notification
 * that calls this.
 *
 * @throws SmsDeliveryException when the provider refuses or is unreachable
 */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
