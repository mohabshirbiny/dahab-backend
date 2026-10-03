<?php

namespace App\Enums;

/** Why staff rejected a withdrawal (spec 013 Clarifications). The customer sees the reason, never the note. */
enum WithdrawalRejectReason: string
{
    case ACCOUNT_NOT_IN_NAME = 'account_not_in_name';
    case MONEY_IN_STRAIGHT_OUT = 'money_in_straight_out';
    case IDENTITY_UNCONFIRMED = 'identity_unconfirmed';
    case CUSTOMER_REQUEST = 'customer_request';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ACCOUNT_NOT_IN_NAME => 'The account is not in your name',
            self::MONEY_IN_STRAIGHT_OUT => 'Money added and taken straight out without trading',
            self::IDENTITY_UNCONFIRMED => 'Your identity could not be confirmed',
            self::CUSTOMER_REQUEST => 'You asked us to stop it',
            self::OTHER => 'We could not send this withdrawal',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::ACCOUNT_NOT_IN_NAME => 'الحساب مش باسمك',
            self::MONEY_IN_STRAIGHT_OUT => 'فلوس اتضافت واتسحبت على طول من غير بيع أو شرا',
            self::IDENTITY_UNCONFIRMED => 'مقدرناش نأكد هويتك',
            self::CUSTOMER_REQUEST => 'انت طلبت نوقفه',
            self::OTHER => 'مقدرناش نبعت السحب ده',
        };
    }
}
