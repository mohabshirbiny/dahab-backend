<?php

namespace App\Actions\Orders\Concerns;

use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\Staff;
use App\Support\Orders\OrderTransitions;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only way an order changes state (spec 012 FR-002, Principle I). Inside
 * the caller's transaction, with the order already locked: check the move
 * against `order_transition`, apply the extra columns, change the state and
 * write the `order_state_change` row naming the actor. The database repeats
 * both checks (trg_order_transition, trg_order_change_recorded — DH006).
 */
trait MovesOrder
{
    /** The order, locked for this transaction. 404 when the current scope cannot see it. */
    private function lockOrder(string $orderId): Order
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An order is locked and moved inside a transaction.');
        }

        return Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Move a locked order. Exactly one of `$byCustomer` / `$byStaff` is the actor.
     *
     * @param  array<string, mixed>  $set  columns written with the move (deadlines, figures)
     */
    private function moveOrder(Order $order, OrderState $to, ?Customer $byCustomer, ?Staff $byStaff, ?string $note = null, array $set = []): Order
    {
        $from = $order->state;

        if (! app(OrderTransitions::class)->allows($from, $to)) {
            throw DomainApiException::illegalOrderTransition();
        }

        $order->forceFill($set);
        $order->state = $to;
        $order->save();

        OrderStateChange::query()->create([
            'order_id' => $order->order_id,
            'from_state' => $from,
            'to_state' => $to,
            'actor_customer_id' => $byCustomer?->customer_id,
            'actor_staff_id' => $byStaff?->staff_id,
            'note' => $note,
        ]);

        return $order;
    }

    /** The first history row: the seller accepted (spec 011 creates the order). */
    private function recordOrderCreated(Order $order, Customer $seller): void
    {
        OrderStateChange::query()->create([
            'order_id' => $order->order_id,
            'from_state' => null,
            'to_state' => $order->state,
            'actor_customer_id' => $seller->customer_id,
            'note' => OrderStateChange::NOTE_ACCEPTED,
        ]);
    }
}
