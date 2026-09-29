<?php

namespace App\Enums;

/**
 * How a customer sends money to Dahab (`topup_method`, spec 009 FR-001).
 * Always a manual transfer to one of Dahab's receiving accounts, never a
 * payment gateway.
 */
enum TopUpMethod: string
{
    case BANK_TRANSFER = 'bank_transfer';
    case INSTAPAY = 'instapay';
    case VODAFONE_CASH = 'vodafone_cash';

    public function label(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'Bank transfer',
            self::INSTAPAY => 'InstaPay',
            self::VODAFONE_CASH => 'Vodafone Cash',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'تحويل بنكي',
            self::INSTAPAY => 'إنستاباي',
            self::VODAFONE_CASH => 'فودافون كاش',
        };
    }

    /**
     * The detail columns customers copy, in display order (contract
     * "ReceivingAccount"). Columns of other methods are always null.
     *
     * @return list<string>
     */
    public function detailKeys(): array
    {
        return match ($this) {
            self::BANK_TRANSFER => ['bank_name', 'account_holder', 'account_number', 'iban'],
            self::INSTAPAY => ['instapay_address', 'account_holder'],
            self::VODAFONE_CASH => ['wallet_number', 'account_holder'],
        };
    }
}
