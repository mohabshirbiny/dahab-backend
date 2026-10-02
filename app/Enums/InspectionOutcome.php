<?php

namespace App\Enums;

/**
 * What an inspection result means (schema `inspection_result.outcome`; Part 3
 * §7.1). Derived by the server from the measured figures and the inspector's
 * two flags, never sent by the client (spec 012 research R9).
 */
enum InspectionOutcome: string
{
    case PASS = 'pass';
    case WEIGHT_ADJUST = 'weight_adjust';
    case STONE_REGRADE = 'stone_regrade';
    case KARAT_CANCEL = 'karat_cancel';
    case FAKE_CANCEL = 'fake_cancel';

    /** The buyer must accept a new price before paying. */
    public function needsDecision(): bool
    {
        return $this === self::WEIGHT_ADJUST || $this === self::STONE_REGRADE;
    }

    /** The sale ends here: the buyer is refunded and the seller suspended. */
    public function cancels(): bool
    {
        return $this === self::KARAT_CANCEL || $this === self::FAKE_CANCEL;
    }
}
