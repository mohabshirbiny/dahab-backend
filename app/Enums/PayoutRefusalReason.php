<?php

namespace App\Enums;

/** Why staff refused a payout account (spec 013 Clarifications). The customer sees the reason, never the note. */
enum PayoutRefusalReason: string
{
    case NAME_MISMATCH = 'name_mismatch';
    case NAME_SHORTENED = 'name_shortened';
    case NOT_IN_CUSTOMER_NAME = 'not_in_customer_name';
    case DETAILS_INVALID = 'details_invalid';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NAME_MISMATCH => 'The name does not match your ID',
            self::NAME_SHORTENED => 'The name is shortened: use your full name as on your ID',
            self::NOT_IN_CUSTOMER_NAME => 'The account is not in your name',
            self::DETAILS_INVALID => 'The account details look wrong',
            self::OTHER => 'We could not accept this account',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NAME_MISMATCH => 'الاسم مش مطابق لهويتك',
            self::NAME_SHORTENED => 'الاسم مختصر: اكتب اسمك كامل زي ما هو في هويتك',
            self::NOT_IN_CUSTOMER_NAME => 'الحساب مش باسمك',
            self::DETAILS_INVALID => 'بيانات الحساب شكلها غلط',
            self::OTHER => 'مقدرناش نقبل الحساب ده',
        };
    }
}
