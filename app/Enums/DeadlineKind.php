<?php

namespace App\Enums;

/**
 * The deadline an order is running against (spec 012 research R19). Only
 * `reach_branch`, `balance` and `collect` can be extended by staff
 * (`order_deadline_extension.which`).
 */
enum DeadlineKind: string
{
    case REACH_BRANCH = 'reach_branch';
    case DECISION = 'decision';
    case BALANCE = 'balance';
    case COLLECT = 'collect';
    case RETURN = 'return';

    public function extendable(): bool
    {
        return in_array($this, [self::REACH_BRANCH, self::BALANCE, self::COLLECT], true);
    }
}
