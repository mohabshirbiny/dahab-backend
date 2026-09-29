<?php

namespace App\Enums;

/**
 * Where a top-up stands (`topup_status`, spec 009 FR-025, data-model
 * "State machine"). Credited, rejected and cancelled are final; the
 * database freezes them too (trg_topup_guard).
 */
enum TopUpStatus: string
{
    case PENDING = 'pending';
    case ON_HOLD = 'on_hold';
    case CREDITED = 'credited';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::CREDITED, self::REJECTED, self::CANCELLED], true);
    }

    public function canMoveTo(self $to): bool
    {
        return match ($this) {
            self::PENDING => in_array($to, [self::ON_HOLD, self::CREDITED, self::REJECTED, self::CANCELLED], true),
            self::ON_HOLD => in_array($to, [self::PENDING, self::CREDITED, self::REJECTED], true),
            self::CREDITED, self::REJECTED, self::CANCELLED => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Waiting to be matched',
            self::ON_HOLD => 'On hold',
            self::CREDITED => 'Credited',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled by the customer',
        };
    }
}
