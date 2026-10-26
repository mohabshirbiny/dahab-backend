<?php

namespace App\Actions\Finance;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * The orders a compensation may be paid for (app notes 2026-10-26: a payment
 * always names its order): the customer's orders as buyer or seller, newest
 * first, open and closed alike.
 */
final class ListCompensationOrdersAction
{
    public const LIMIT = 100;

    /** @return Collection<int, Order> */
    public function handle(string $customerId): Collection
    {
        return Order::query()
            ->where(fn ($q) => $q->where('buyer_id', $customerId)->orWhere('seller_id', $customerId))
            ->with(['listing.pieceType'])
            ->orderByDesc('accepted_at')->orderByDesc('order_id')
            ->limit(self::LIMIT)
            ->get();
    }
}
