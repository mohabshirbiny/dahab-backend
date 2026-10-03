<?php

namespace App\Enums;

/** Why staff put a withdrawal on hold (the design's list, spec 013). The customer is told the staff's message, not this code. */
enum WithdrawalHoldReason: string
{
    case NAME_MISMATCH = 'name_mismatch';
    case ACCOUNT_CHANGED_RECENTLY = 'account_changed_recently';
    case IDENTITY_PENDING = 'identity_pending';
    case MONEY_IN_STRAIGHT_OUT = 'money_in_straight_out';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NAME_MISMATCH => 'Name does not match the ID',
            self::ACCOUNT_CHANGED_RECENTLY => 'Bank account changed recently',
            self::IDENTITY_PENDING => 'Waiting for identity verification',
            self::MONEY_IN_STRAIGHT_OUT => 'Money came in and is going straight out',
            self::OTHER => 'Something else',
        };
    }
}
