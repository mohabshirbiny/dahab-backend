<?php

namespace App\Notifications\Messages;

/**
 * What a notification's `toSms()` returns. Deliberately minimal — an SMS is a
 * recipient plus a body, and the recipient comes from the notifiable's route.
 */
final class SmsMessage
{
    public function __construct(public readonly string $content) {}

    public static function make(string $content): self
    {
        return new self($content);
    }
}
