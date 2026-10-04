<?php

namespace App\Enums;

/** What went wrong on an order — the prototype's six choices (spec 014 FR-001). The fifth is the buyer's only. */
enum DisputeReason: string
{
    case NOT_AS_LISTED = 'not_as_listed';
    case DISAGREE_INSPECTION = 'disagree_inspection';
    case MONEY_WRONG = 'money_wrong';
    case OTHER_SIDE_UNRESPONSIVE = 'other_side_unresponsive';
    case NOT_THEIRS_TO_SELL = 'not_theirs_to_sell';
    case OTHER = 'other';

    public function buyerOnly(): bool
    {
        return $this === self::NOT_THEIRS_TO_SELL;
    }

    public function label(): string
    {
        return match ($this) {
            self::NOT_AS_LISTED => 'The piece is not what the listing showed',
            self::DISAGREE_INSPECTION => 'Disagrees with the inspection result',
            self::MONEY_WRONG => 'Money is missing or wrong',
            self::OTHER_SIDE_UNRESPONSIVE => 'The other side is not responding',
            self::NOT_THEIRS_TO_SELL => 'Thinks the piece is not theirs to sell',
            self::OTHER => 'Something else',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NOT_AS_LISTED => 'القطعة مش زي اللي في الإعلان',
            self::DISAGREE_INSPECTION => 'مش موافق على نتيجة الفحص',
            self::MONEY_WRONG => 'في فلوس ناقصة أو غلط',
            self::OTHER_SIDE_UNRESPONSIVE => 'الطرف التاني مش بيرد',
            self::NOT_THEIRS_TO_SELL => 'شاكك إن القطعة مش بتاعته عشان يبيعها',
            self::OTHER => 'حاجة تانية',
        };
    }
}
