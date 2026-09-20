<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of sending it. The default driver, so
 * the registration flow works end to end before a provider is configured.
 *
 * The body is logged in full: this driver is only ever meant for local and
 * testing environments, where the OTP in the log is the point.
 */
final class LogSmsSender implements SmsSender
{
    public function __construct(private readonly ?string $channel = null) {}

    public function send(string $to, string $message): void
    {
        Log::channel($this->channel)->info('sms.sent', [
            'driver' => 'log',
            'to' => $to,
            'message' => $message,
        ]);
    }
}
