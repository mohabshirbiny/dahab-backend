<?php

namespace App\Support\Wallet;

/**
 * The customer's own words for a wallet movement on the statement PDF (wallet statement PDF,
 * item 10): the same English and Arabic the app shows for each row of
 * `GET /customer/me/wallet/transactions` (`lib/models/wallet_labels.dart` and its Arabic
 * dictionary), so the PDF and the screen agree. A withdrawal is three steps of one ledger kind:
 * set aside (out of available), sent (out of held) or returned (back to available).
 */
final class CustomerStatementLabels
{
    /** @var array<string, array{0: string, 1: string}> kind => [English, Arabic] */
    private const LABELS = [
        'topup' => ['Added by bank transfer', 'شحن بتحويل بنكي'],
        'deposit_hold' => ['Deposit held on a purchase', 'عربون محجوزة على عملية شراء'],
        'deposit_release' => ['Deposit returned', 'عربون راجعة'],
        'deposit_forfeit' => ['Deposit kept after a missed payment', 'العربون اتخصم بعد تأخير الدفع'],
        'settlement_seller' => ['Sale settled', 'تسوية بيعة'],
        'first_sale_payout' => ['First sale paid early', 'أول بيعة اتدفعت بدري'],
        'balance_payment' => ['Balance paid on a purchase', 'باقي المبلغ اتدفع على شرا'],
        'withdrawal' => ['Withdrawn to your bank', 'اتسحبت لحسابك في البنك'],
        'withdrawal_hold' => ['Withdrawal requested', 'طلب السحب اتسجل'],
        'withdrawal_return' => ['Withdrawal returned', 'السحبة رجعت'],
        'compensation' => ['Compensation from Dahab', 'تعويض من دهب'],
        'weight_adjustment' => ['Weight adjustment', 'تعديل وزن'],
        'reversal' => ['Correction', 'تصحيح'],
        'credit_note' => ['Invoice correction', 'تصحيح فاتورة'],
    ];

    private const OTHER = ['Wallet movement', 'حركة في المحفظة'];

    /**
     * The label key of one row, as the app derives it.
     */
    public static function keyOf(string $kind, string $availableChange): string
    {
        if ($kind !== 'withdrawal') {
            return $kind;
        }

        return match (bccomp($availableChange, '0', 4)) {
            -1 => 'withdrawal_hold',
            1 => 'withdrawal_return',
            default => 'withdrawal',
        };
    }

    /** @return array{en: string, ar: string} */
    public static function of(string $key): array
    {
        [$en, $ar] = self::LABELS[$key] ?? self::OTHER;

        return ['en' => $en, 'ar' => $ar];
    }
}
