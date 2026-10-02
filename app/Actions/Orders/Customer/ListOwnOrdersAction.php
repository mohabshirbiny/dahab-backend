<?php

namespace App\Actions\Orders\Customer;

use App\Enums\OrderState;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A customer's own orders, as buyer or seller (spec 012 FR-001, research
 * R19). The rows are read under the customer's plain row isolation, so
 * `order_isolation` alone decides which exist; only then, in the read-only use
 * of the `order` scope, are the counterparty's display reference, the request
 * and the piece loaded for those orders (research R2). Newest first, keyset.
 */
final class ListOwnOrdersAction
{
    /** @return array{rows: Collection<int, Order>, next_cursor: string|null} */
    public function handle(string $customerId, ?string $role, ?string $group, ?ListingCursor $cursor, int $perPage): array
    {
        $query = Order::query()
            ->when($role === 'buyer', fn (Builder $q) => $q->where('buyer_id', $customerId))
            ->when($role === 'seller', fn (Builder $q) => $q->where('seller_id', $customerId))
            ->when($group === 'open', fn (Builder $q) => $q->whereIn('state', array_map(fn ($s) => $s->value, OrderState::open())))
            ->when($group === 'closed', fn (Builder $q) => $q->whereNotIn('state', array_map(fn ($s) => $s->value, OrderState::open())))
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accepted_at', '<', $cursor->value)
                ->orWhere(fn (Builder $e) => $e->where('accepted_at', $cursor->value)->where('order_id', '<', $cursor->id))))
            ->orderByDesc('accepted_at')->orderByDesc('order_id')
            ->limit($perPage + 1);

        $rows = $query->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        $this->load($rows);

        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->accepted_at->format('Y-m-d H:i:s.uP'), $last->order_id))->encode()
                : null,
        ];
    }

    /** One of the customer's orders; 404 for anyone else's (row isolation). */
    public function show(string $orderId): Order
    {
        $order = Order::query()->findOrFail($orderId);
        $this->load(new Collection([$order]));

        return $order;
    }

    /** @param  Collection<int, Order>  $orders */
    private function load(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        DatabaseActor::order(fn () => $orders->load([
            'listing.pieceType', 'listing.photos', 'branch.hours', 'buyRequest',
            'seller:customer_id,display_ref', 'buyer:customer_id,display_ref',
            'inspections', 'decisions', 'collection', 'sellerReturn',
            'stateChanges', 'branchChanges', 'extensions',
        ]));
    }
}
