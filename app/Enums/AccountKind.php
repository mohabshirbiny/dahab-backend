<?php

namespace App\Enums;

/**
 * Where money can sit (docs/Database schema/03_schema_ledger.sql,
 * `account_kind`). Customer kinds belong to one customer; internal kinds
 * exist once each.
 */
enum AccountKind: string
{
    case CUST_AVAILABLE = 'cust_available';
    case CUST_HELD = 'cust_held';
    case ESCROW = 'escrow';
    case DAHAB_COMMISSION = 'dahab_commission';
    case DAHAB_SPREAD = 'dahab_spread';
    case VAT_PAYABLE = 'vat_payable';
    case BANK = 'bank';
    case EXTERNAL_EQUITY = 'external_equity';

    public function isCustomer(): bool
    {
        return $this === self::CUST_AVAILABLE || $this === self::CUST_HELD;
    }

    /** @return list<self> */
    public static function internal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind) => ! $kind->isCustomer()));
    }
}
