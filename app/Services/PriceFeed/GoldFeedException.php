<?php

namespace App\Services\PriceFeed;

use RuntimeException;

/**
 * A failed reading. Messages are written here, never copied from the
 * provider, so they cannot carry a credential or a token.
 */
final class GoldFeedException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('The provider is unreachable (connection error or timeout).');
    }

    public static function signInRefused(int $status): self
    {
        return new self("The provider refused the sign-in (HTTP {$status}).");
    }

    public static function http(string $step, int $status): self
    {
        return new self("The provider answered HTTP {$status} to the {$step} call.");
    }

    public static function unusable(string $reason): self
    {
        return new self("The provider's answer is unusable: {$reason}.");
    }
}
