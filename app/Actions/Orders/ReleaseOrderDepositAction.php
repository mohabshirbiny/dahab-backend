<?php

namespace App\Actions\Orders;

use App\Models\BuyRequest;
use App\Models\Order;
use App\Support\BuyRequests\DepositLedger;

/**
 * Give an order's buyer their deposit back in full (spec 012 FR-005, FR-012,
 * FR-013): one `deposit_release` through the money service, tied to the
 * request and the order, linked from `order.release_txn_id`. Inside the
 * caller's transaction; trg_order_money checks the cancellation has it.
 */
final class ReleaseOrderDepositAction
{
    public function __construct(private readonly DepositLedger $deposits) {}

    public function handle(Order $order, ?string $actorCustomerId, ?string $actorStaffId): string
    {
        $request = BuyRequest::query()->whereKey($order->buy_request_id)->firstOrFail();
        $txn = $this->deposits->release($request, $actorCustomerId, $actorStaffId, $order->order_id);

        $order->forceFill(['release_txn_id' => $txn->ledger_txn_id])->save();

        return (string) $request->deposit_amount;
    }
}
