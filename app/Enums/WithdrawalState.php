<?php

namespace App\Enums;

/**
 * Where a withdrawal stands (`withdrawal_state`, spec 013). Mirrors
 * `withdrawal_transition`; the database guard (SQLSTATE DH007) is the
 * backstop. `on_hold_account_change` and `settled` exist in the schema but
 * are never reached in this feature (Clarifications).
 */
enum WithdrawalState: string
{
    case REQUESTED = 'requested';
    case UNDER_REVIEW = 'under_review';
    case ON_HOLD_ACCOUNT_CHANGE = 'on_hold_account_change';
    case RELEASED = 'released';
    case SETTLED = 'settled';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    /** Not released yet: its money is held and a change of the account in use cancels it. */
    public function isOpen(): bool
    {
        return $this === self::REQUESTED || $this === self::UNDER_REVIEW;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::REQUESTED->value, self::UNDER_REVIEW->value];
    }

    public function canMoveTo(self $to): bool
    {
        return match ($this) {
            self::REQUESTED => in_array($to, [self::UNDER_REVIEW, self::CANCELLED, self::REJECTED, self::ON_HOLD_ACCOUNT_CHANGE], true),
            self::UNDER_REVIEW => in_array($to, [self::RELEASED, self::REJECTED, self::CANCELLED], true),
            self::ON_HOLD_ACCOUNT_CHANGE => $to === self::UNDER_REVIEW,
            self::RELEASED => $to === self::SETTLED,
            self::SETTLED, self::REJECTED, self::CANCELLED => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Waiting for review',
            self::UNDER_REVIEW => 'Under review',
            self::ON_HOLD_ACCOUNT_CHANGE => 'Paused for an account change',
            self::RELEASED => 'Sent to the bank',
            self::SETTLED => 'Arrived',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
        };
    }
}
