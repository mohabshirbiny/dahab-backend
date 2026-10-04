<?php

namespace App\Enums;

/** Which way a wallet adjustment moves the customer's available balance (spec 015). */
enum AdjustmentDirection: string
{
    case CREDIT = 'credit';
    case DEBIT = 'debit';

    /** The signed change to the customer's available account. */
    public function signed(string $amount): string
    {
        return $this === self::CREDIT ? $amount : '-'.$amount;
    }
}
