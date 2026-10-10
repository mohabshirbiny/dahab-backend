<?php

namespace App\Actions\Orders\Staff;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One order for staff (spec 012 FR-022, research R18), in the staff scope:
 * everything the detail shows, plus the ledger entries tied to the order or
 * to its buy request (the deposit hold), each line labelled with whose money
 * it is. No collection code is loaded into the answer.
 */
final class ShowOrderAction
{
    public const RELATIONS = [
        'listing.pieceType', 'listing.photos', 'branch', 'buyRequest',
        'seller:customer_id,display_ref', 'buyer:customer_id,display_ref',
        'inspections', 'decisions', 'collection', 'sellerReturn',
        'stateChanges', 'branchChanges', 'extensions',
        // Spec 014: the disputes, the seller's requests for more time.
        'disputes', 'extensionRequests',
        // Spec 018: the free relist made from it, and what it was relisted from.
        'freeRelistListing.pieceType', 'listing.relistedFrom:order_id,order_ref',
    ];

    public function handle(string $orderId): Order
    {
        $order = Order::query()->with(self::RELATIONS)->findOrFail($orderId);
        $order->setAttribute('ledger_entries', $this->ledger($order));

        return $order;
    }

    /** @param  Collection<int, Order>  $orders */
    public static function loadFor(Collection $orders): void
    {
        $orders->load(self::RELATIONS);
    }

    /** @return list<array<string, mixed>> */
    private function ledger(Order $order): array
    {
        $rows = DB::table('ledger_transaction as t')
            ->join('ledger_posting as p', 'p.ledger_txn_id', '=', 't.ledger_txn_id')
            ->join('account as a', 'a.account_id', '=', 'p.account_id')
            ->where(fn ($q) => $q->where('t.order_id', $order->order_id)->orWhere('t.buy_request_id', $order->buy_request_id))
            ->orderBy('t.created_at')->orderBy('p.posting_id')
            ->get(['t.ledger_txn_id', 't.event_kind', 't.created_at', 'a.kind', 'a.customer_id', 'p.amount']);

        return $rows->groupBy('ledger_txn_id')->map(function ($lines) use ($order) {
            $first = $lines->first();

            return [
                'ledger_txn_id' => $first->ledger_txn_id,
                'event_kind' => $first->event_kind,
                'created_at' => CarbonImmutable::parse($first->created_at)->toIso8601String(),
                'lines' => $lines->map(fn ($l) => [
                    'account' => $l->kind,
                    'party' => match ($l->customer_id) {
                        null => 'dahab',
                        $order->buyer_id => 'buyer',
                        $order->seller_id => 'seller',
                        default => 'other',
                    },
                    'amount' => bcadd((string) $l->amount, '0', 4),
                ])->values()->all(),
            ];
        })->values()->all();
    }
}
