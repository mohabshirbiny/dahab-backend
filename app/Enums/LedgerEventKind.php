<?php

namespace App\Enums;

/**
 * What a ledger entry is (`ledger_event_kind`, docs/Database
 * schema/01_schema_core.sql). Staff see `staffLabel()` (the Dashboard
 * design's wording); the Customer App localises the code itself (spec 008
 * FR-020, research R9).
 */
enum LedgerEventKind: string
{
    case TOPUP = 'topup';
    case DEPOSIT_HOLD = 'deposit_hold';
    case DEPOSIT_RELEASE = 'deposit_release';
    case DEPOSIT_FORFEIT = 'deposit_forfeit';
    case SETTLEMENT_SELLER = 'settlement_seller';
    case FIRST_SALE_PAYOUT = 'first_sale_payout';
    case COMMISSION = 'commission';
    case SPREAD = 'spread';
    case VAT = 'vat';
    case BALANCE_PAYMENT = 'balance_payment';
    case WITHDRAWAL = 'withdrawal';
    case COMPENSATION = 'compensation';
    case EXTERNAL_BANK_MOVEMENT = 'external_bank_movement';
    case WEIGHT_ADJUSTMENT = 'weight_adjustment';
    case REVERSAL = 'reversal';
    case CREDIT_NOTE = 'credit_note';

    /** No default arm: a new case must get a label. */
    public function staffLabel(): string
    {
        return match ($this) {
            self::TOPUP => 'Added by bank transfer',
            self::DEPOSIT_HOLD => 'Deposit held',
            self::DEPOSIT_RELEASE => 'Deposit returned',
            self::DEPOSIT_FORFEIT => 'Deposit forfeited',
            self::SETTLEMENT_SELLER => 'Sale settled',
            self::FIRST_SALE_PAYOUT => 'Paid ahead of the buyer (first sale)',
            self::COMMISSION => 'Commission on a sale',
            self::SPREAD => 'Spread on a sale',
            self::VAT => 'VAT on commission',
            self::BALANCE_PAYMENT => 'Balance paid',
            self::WITHDRAWAL => 'Withdrawn',
            self::COMPENSATION => 'Compensation from Dahab',
            self::EXTERNAL_BANK_MOVEMENT => 'Bank movement',
            self::WEIGHT_ADJUSTMENT => 'Weight adjustment',
            self::REVERSAL => 'Correction (reversal)',
            self::CREDIT_NOTE => 'Invoice correction (credit note)',
        };
    }
}
