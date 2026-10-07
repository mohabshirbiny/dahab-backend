<?php

namespace App\Support\Account;

use App\Enums\AccountKind;
use App\Enums\BuyRequestState;
use App\Enums\DisputeState;
use App\Enums\ExtensionRequestState;
use App\Enums\ListingState;
use App\Enums\OrderState;
use App\Enums\TopUpStatus;
use App\Enums\WithdrawalState;
use App\Models\Account;
use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;

/**
 * What stops a customer closing their account (spec 017 FR-050, research R8):
 * anything in progress, or any money. Each blocker comes with how many rows it
 * counts. Read in the system scope: an open order or dispute may have been
 * raised by the other party.
 */
final class CloseBlockers
{
    /** @return list<array{code: string, count: int}> */
    public function for(string $customerId): array
    {
        return DatabaseActor::elevate('system', function () use ($customerId) {
            $orders = DB::table('order')->where(fn ($q) => $q->where('buyer_id', $customerId)->orWhere('seller_id', $customerId));

            $counts = [
                'open_order' => (clone $orders)->whereIn('state', array_map(fn (OrderState $s) => $s->value, OrderState::open()))->count(),
                'active_buy_request' => DB::table('buy_request')->where('buyer_id', $customerId)
                    ->whereIn('state', [BuyRequestState::QUEUED->value, BuyRequestState::ACCEPTED->value])->count(),
                'listing_in_sale' => DB::table('listing')->where('seller_id', $customerId)
                    ->whereIn('state', [ListingState::RESERVED->value, ListingState::ACCEPTED->value, ListingState::AT_INSPECTION->value, ListingState::SETTLING->value])->count(),
                'piece_at_branch' => DB::table('listing')->where('seller_id', $customerId)
                    ->whereIn('state', [ListingState::AWAITING_SELLER_RETURN->value, ListingState::SELLER_UNCLAIMED->value])->count()
                    + DB::table('order')->join('listing', 'listing.listing_id', '=', 'order.listing_id')
                        ->where('order.buyer_id', $customerId)->where('listing.state', ListingState::UNCOLLECTED_EXPIRED->value)->count(),
                'wallet_balance' => $this->moneyLeft($customerId) ? 1 : 0,
                'pending_withdrawal' => DB::table('withdrawal')->where('customer_id', $customerId)->whereIn('state', WithdrawalState::openValues())->count(),
                'open_dispute' => DB::table('dispute')->join('order', 'order.order_id', '=', 'dispute.order_id')
                    ->where(fn ($q) => $q->where('order.buyer_id', $customerId)->orWhere('order.seller_id', $customerId))
                    ->whereIn('dispute.state', [DisputeState::OPEN->value, DisputeState::PASSED_ON->value])->count(),
                'pending_extension_request' => DB::table('order_extension_request')->join('order', 'order.order_id', '=', 'order_extension_request.order_id')
                    ->where(fn ($q) => $q->where('order.buyer_id', $customerId)->orWhere('order.seller_id', $customerId))
                    ->where('order_extension_request.state', ExtensionRequestState::WAITING->value)->count(),
                'pending_topup' => DB::table('topup')->where('customer_id', $customerId)
                    ->whereIn('status', [TopUpStatus::PENDING->value, TopUpStatus::ON_HOLD->value])->count(),
            ];

            $blockers = [];
            foreach ($counts as $code => $count) {
                if ($count > 0) {
                    $blockers[] = ['code' => $code, 'count' => $count];
                }
            }

            return $blockers;
        });
    }

    /** Anything on the customer's available or held account. */
    private function moneyLeft(string $customerId): bool
    {
        return DatabaseActor::ledger(fn () => DB::table('ledger_posting')
            ->whereIn('account_id', [
                Account::forCustomerKind($customerId, AccountKind::CUST_AVAILABLE),
                Account::forCustomerKind($customerId, AccountKind::CUST_HELD),
            ])
            ->select('account_id')->groupBy('account_id')->havingRaw('SUM(amount) <> 0')->exists());
    }
}
