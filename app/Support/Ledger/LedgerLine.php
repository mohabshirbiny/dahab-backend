<?php

namespace App\Support\Ledger;

use InvalidArgumentException;

/**
 * One signed line of a ledger entry (spec 008 FR-006): + increases the
 * account's balance, − decreases it. Exact to 4 decimal places, never zero,
 * never a float. Callers round (half-up, residue to a Dahab account —
 * Part 3 §3.5) before building a line.
 */
final readonly class LedgerLine
{
    public const AMOUNT_PATTERN = '/^-?\d{1,14}(\.\d{1,4})?$/';

    public function __construct(
        public string $accountId,
        public string $amount,
    ) {
        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException("Ledger amount [{$amount}] must be a decimal string with at most 4 places.");
        }

        if (bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('A ledger line cannot be zero.');
        }
    }
}
