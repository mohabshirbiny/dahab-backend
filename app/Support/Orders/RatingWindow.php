<?php

namespace App\Support\Orders;

use App\Enums\OrderState;
use App\Enums\PartyRole;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * When a party may rate an order (spec 018 FR-030–FR-032, research R12). Not
 * stored: the seller's window opens when the order became `ready_to_collect`
 * (the settled moment, read from its history), the buyer's when it was
 * completed; both close thirty calendar days later. Nothing opens for an
 * order that was never settled or that ended cancelled.
 */
final class RatingWindow
{
    public const DAYS = 30;

    public function __construct(
        public readonly CarbonImmutable $opensAt,
        public readonly CarbonImmutable $closesAt,
    ) {}

    /** The window of `$role` on `$order`, or null when this party cannot rate it at all (yet). */
    public static function of(Order $order, PartyRole $role): ?self
    {
        if ($order->settlement_txn_id === null || $order->state->isCancelled()) {
            return null;
        }

        if ($role === PartyRole::BUYER) {
            $opens = $order->state === OrderState::COMPLETED ? $order->completed_at : null;
        } else {
            $changes = $order->relationLoaded('stateChanges') ? $order->stateChanges : $order->stateChanges()->get();
            $opens = $changes->filter(fn ($c) => $c->to_state === OrderState::READY_TO_COLLECT)->sortBy('change_id')->first()?->changed_at
                ?? $order->completed_at;
        }

        return $opens === null ? null : new self($opens, $opens->addDays(self::DAYS));
    }

    public function isOpen(?CarbonImmutable $now = null): bool
    {
        return ! ($now ?? CarbonImmutable::now())->greaterThan($this->closesAt);
    }
}
