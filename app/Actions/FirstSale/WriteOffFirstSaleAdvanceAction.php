<?php

namespace App\Actions\FirstSale;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AdvanceStatus;
use App\Enums\AuditEvent;
use App\Enums\OrderState;
use App\Enums\WriteOffReason;
use App\Models\Customer;
use App\Models\FirstSaleAdvance;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Models\Staff;

/**
 * An order that ends without a payment writes its advance off (spec 023 research R14; TS Part 3 §6.5).
 *
 * No ledger posting: the advance already left `external_equity` when it was paid, so writing it off
 * is a status change that records the reason and takes it out of the exposure sum. The seller keeps
 * the piece and the advance; nothing is clawed back. Called from `MovesOrder::moveOrder` for every
 * cancelled state, in the same transaction as the move.
 */
final class WriteOffFirstSaleAdvanceAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Order $order, OrderState $to, ?string $note, ?Customer $byCustomer, ?Staff $byStaff): void
    {
        $advance = FirstSaleAdvance::query()->where('order_id', $order->order_id)
            ->where('status', AdvanceStatus::OUTSTANDING->value)->lockForUpdate()->first();
        if ($advance === null) {
            return;
        }

        $reason = match (true) {
            $to === OrderState::CANCELLED_BUYER_NOPAY => WriteOffReason::BUYER_DID_NOT_PAY,
            $to === OrderState::CANCELLED_STAFF => WriteOffReason::CANCELLED_BY_STAFF,
            $note === OrderStateChange::NOTE_DISPUTE_AGAINST_SALE => WriteOffReason::DISPUTE_AGAINST_SALE,
            default => WriteOffReason::CANCELLED_AT_INSPECTION,
        };

        $advance->forceFill([
            'status' => AdvanceStatus::WRITTEN_OFF,
            'written_off_at' => now(),
            'written_off_reason' => $reason,
            'written_off_amount_egp' => $advance->amount_egp,
        ])->save();

        $this->audit->execute(
            AuditEvent::FIRST_SALE_WRITTEN_OFF,
            'success',
            ['order_ref' => $order->order_ref, 'amount' => bcadd((string) $advance->amount_egp, '0', 4), 'reason' => $reason->value, 'seller_id' => $order->seller_id],
            'order',
            $order->order_id,
            actorCustomerId: $byCustomer?->customer_id,
            actorStaffId: $byCustomer === null ? $byStaff?->staff_id : null,
        );
    }
}
