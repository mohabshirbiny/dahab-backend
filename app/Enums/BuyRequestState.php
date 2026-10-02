<?php

namespace App\Enums;

/**
 * `buy_request_state` (schema §1, spec 011). A request is born `queued` and
 * leaves it once, along a row of `buy_request_transition`; every other state
 * is final. The database refuses anything else (trg_buy_request_guard).
 */
enum BuyRequestState: string
{
    case QUEUED = 'queued';
    case ACCEPTED = 'accepted';
    case RELEASED_NOT_CHOSEN = 'released_not_chosen';
    case RELEASED_DECLINED = 'released_declined';
    case RELEASED_EXPIRED = 'released_expired';
    case WITHDRAWN_BY_BUYER = 'withdrawn_by_buyer';

    public function isFinal(): bool
    {
        return $this !== self::QUEUED;
    }

    /** Queued or accepted: the deposit is still held (unless the order was cancelled). */
    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::ACCEPTED;
    }

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'In line',
            self::ACCEPTED => 'Accepted',
            self::RELEASED_NOT_CHOSEN => 'Not chosen',
            self::RELEASED_DECLINED => 'Declined',
            self::RELEASED_EXPIRED => 'Expired',
            self::WITHDRAWN_BY_BUYER => 'Left the queue',
        };
    }
}
