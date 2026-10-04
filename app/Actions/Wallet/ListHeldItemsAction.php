<?php

namespace App\Actions\Wallet;

use App\Enums\AccountKind;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;

/**
 * What each of a customer's buy requests and orders holds now (spec 015
 * FR-019, research R12): the held postings of the customer's own held account
 * grouped by the request each entry names, non-zero only. A request accepted
 * into an order is shown as that order. The total is the wallet's
 * held_on_orders (a withdrawal's hold names no request). Newest first.
 */
final class ListHeldItemsAction
{
    /** @return array{total: string, items: list<array<string, mixed>>} */
    public function handle(string $customerId): array
    {
        $held = DatabaseActor::ledger(fn () => DB::table('ledger_posting AS p')
            ->join('ledger_transaction AS t', 't.ledger_txn_id', '=', 'p.ledger_txn_id')
            ->where('p.account_id', Account::forCustomerKind($customerId, AccountKind::CUST_HELD))
            ->whereNotNull('t.buy_request_id')
            ->groupBy('t.buy_request_id')
            ->havingRaw('SUM(p.amount) <> 0')
            ->selectRaw('t.buy_request_id::text AS id, SUM(p.amount)::numeric(18,4)::text AS amount')
            ->pluck('amount', 'id')->all());

        $requests = BuyRequest::query()->whereIn('buy_request_id', array_keys($held))->where('buyer_id', $customerId)
            ->with(['listing.pieceType', 'order'])->orderByDesc('requested_at')->get();

        $items = $requests->map(fn (BuyRequest $r) => $r->order !== null ? [
            'type' => 'order',
            'id' => $r->order->order_id,
            'ref' => $r->order->order_ref,
            'title' => $r->listing?->title(),
            'title_ar' => $r->listing?->title(arabic: true),
            'state' => $r->order->state->value,
            'amount' => $held[$r->buy_request_id],
        ] : [
            'type' => 'buy_request',
            'id' => $r->buy_request_id,
            'ref' => null,
            'title' => $r->listing?->title(),
            'title_ar' => $r->listing?->title(arabic: true),
            'state' => $r->state->value,
            'amount' => $held[$r->buy_request_id],
        ])->values()->all();

        $total = array_reduce($items, fn (string $sum, array $i) => bcadd($sum, $i['amount'], 4), '0.0000');

        return ['total' => $total, 'items' => $items];
    }
}
