<?php

namespace App\Enums;

/** What a payout or withdrawal message is about (spec 013 FR-018, research R11). */
enum PayoutEvent: string
{
    case ACCOUNT_ADDED = 'account_added';
    case ACCOUNT_VERIFIED = 'account_verified';
    case ACCOUNT_REFUSED = 'account_refused';
    case ACCOUNT_IN_USE = 'account_in_use';
    case ACCOUNT_REMOVED = 'account_removed';
    case WITHDRAWAL_HELD = 'withdrawal_held';
    case WITHDRAWAL_RELEASED = 'withdrawal_released';
    case WITHDRAWAL_REJECTED = 'withdrawal_rejected';
    case WITHDRAWALS_CANCELLED_BY_CHANGE = 'withdrawals_cancelled_by_change';
    case WITHDRAWALS_OPEN = 'withdrawals_open';
}
