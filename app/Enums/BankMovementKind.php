<?php

namespace App\Enums;

/**
 * What a bank movement outside the app was (spec 015 research R6): the
 * Dashboard design's seven kinds; rent is an operating expense. A transfer
 * between Dahab's own accounts moves nothing in the ledger.
 */
enum BankMovementKind: string
{
    case CAPITAL_IN = 'capital_in';
    case OPERATING_EXPENSE = 'operating_expense';
    case BANK_CHARGE = 'bank_charge';
    case PROFIT_DRAW = 'profit_draw';
    case OWN_TRANSFER = 'own_transfer';
    case SUPPLIER_REFUND = 'supplier_refund';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CAPITAL_IN => 'Capital paid in',
            self::OPERATING_EXPENSE => 'Operating expense',
            self::BANK_CHARGE => 'Bank charge',
            self::PROFIT_DRAW => 'Profit taken out',
            self::OWN_TRANSFER => 'Transfer between our own accounts',
            self::SUPPLIER_REFUND => 'Refund from a supplier',
            self::OTHER => 'Something else',
        };
    }

    /** Every kind but an own-account transfer is one ledger entry bank <-> external_equity. */
    public function postsToLedger(): bool
    {
        return $this !== self::OWN_TRANSFER;
    }
}
