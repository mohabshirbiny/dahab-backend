<?php

namespace App\Support\Authorization;

use App\Exceptions\DomainApiException;

/**
 * A written reason is required for access changes (spec 002 FR-055, Part 1
 * §6). FormRequests enforce it first; Actions re-check so the rule holds on
 * every path.
 */
final class ReasonRule
{
    public const MIN = 5;

    public const MAX = 500;

    public static function assertPresent(?string $reason): void
    {
        if ($reason === null || mb_strlen(trim($reason)) < self::MIN) {
            throw DomainApiException::reasonRequired();
        }
    }
}
