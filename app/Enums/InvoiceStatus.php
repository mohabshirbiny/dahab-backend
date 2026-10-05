<?php

namespace App\Enums;

use App\Support\Pricing\Money;

/**
 * An invoice's status, derived from its credit notes (spec 016 FR-022): never
 * a Tax Authority status — e-invoicing is not integrated.
 */
enum InvoiceStatus: string
{
    case ISSUED = 'issued';
    case PARTLY_CREDITED = 'partly_credited';
    case CREDITED = 'credited';

    public static function of(string $credited, string $gross): self
    {
        return match (true) {
            Money::cmp($credited, '0') <= 0 => self::ISSUED,
            Money::cmp($credited, $gross) >= 0 => self::CREDITED,
            default => self::PARTLY_CREDITED,
        };
    }
}
