<?php

use App\Enums\AccountKind;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 T074, research R12: build orders into every state through the API,
// then check each against its ledger entries — the deposit held while open,
// one release on a cancellation, one forfeit split between the seller and
// Dahab on a no-pay, one payment through escrow on a paid order with lines
// equal to the stored figures — and the ledger still sums to zero.

/** The order's ledger lines of one kind: [account kind, party, amount]. */
function reconLines(Order $order, string $kind): array
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction as t')
        ->join('ledger_posting as p', 'p.ledger_txn_id', '=', 't.ledger_txn_id')
        ->join('account as a', 'a.account_id', '=', 'p.account_id')
        ->where(fn ($q) => $q->where('t.order_id', $order->order_id)->orWhere('t.buy_request_id', $order->buy_request_id))
        ->where('t.event_kind', $kind)
        ->get(['t.ledger_txn_id', 'a.kind', 'a.customer_id', DB::raw('p.amount::text AS amount')])
        ->map(fn ($l) => ['txn' => $l->ledger_txn_id, 'kind' => $l->kind, 'party' => match ($l->customer_id) {
            null => 'dahab', $order->buyer_id => 'buyer', $order->seller_id => 'seller', default => 'other',
        }, 'amount' => bcadd($l->amount, '0', 4)])->all());
}

function reconTxns(Order $order, string $kind): int
{
    return count(array_unique(array_column(reconLines($order, $kind), 'txn')));
}

function reconSum(array $lines, callable $where): string
{
    return array_reduce(array_filter($lines, $where), fn (string $s, array $l) => bcadd($s, $l['amount'], 4), '0.0000');
}

it('reconciles every order with its ledger entries', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();

    $decision = fn (Order $o, bool $accept) => Listings::as($this, Orders::buyer($o))->postJson(Orders::CUSTOMER_URL."/{$o->order_id}/decision",
        ['accept' => $accept, 'inspection_id' => DatabaseActor::elevate('maintenance', fn () => DB::table('inspection_result')->where('order_id', $o->order_id)->orderByDesc('created_at')->value('inspection_id'))],
        Listings::key())->assertOk();

    // The no-pay first: its sweep must not reach the other orders' deadlines.
    $noPay = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->travelTo($noPay->refresh()->balance_due_deadline->addMinute());
    Orders::sweep();
    $this->travelBack();

    // Open orders.
    Orders::accepted($this);
    $atIgi = Orders::accepted($this);
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $atIgi)->assertOk();
    Orders::inspected($this, Orders::accepted($this), '9.500');
    Orders::inspected($this, Orders::accepted($this), '10.000');

    // Paid, and paid and collected.
    $paid = Orders::inspected($this, Orders::accepted($this), '10.000');
    Orders::pay($this, Orders::buyer($paid), $paid)->assertOk();
    $done = Orders::inspected($this, Orders::accepted($this), '9.500');
    $decision($done, true);
    Orders::pay($this, Orders::buyer($done), $done)->assertOk();
    $code = DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $done->order_id)->sole()->code_encrypted);
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $this->postJson(Orders::STAFF_URL."/{$done->order_id}/handover", ['code' => $code], Listings::key())->assertOk();

    // Cancelled every way.
    $bySeller = Orders::accepted($this);
    Orders::cancel($this, Orders::seller($bySeller), $bySeller)->assertOk();
    Orders::inspected($this, Orders::accepted($this), '10.000', 18);
    $declined = Orders::inspected($this, Orders::accepted($this), '9.500');
    $decision($declined, false);
    $byStaff = Orders::accepted($this);
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->postJson(BuyRequests::ORDERS_URL."/{$byStaff->order_id}/cancel", ['reason' => 'The piece was damaged before delivery.', 'relist' => true], Listings::key())->assertOk();

    BuyRequests::checkNow();

    $orders = DatabaseActor::elevate('maintenance', fn () => Order::query()->with('buyRequest')->get());
    expect($orders->pluck('state')->map->value->unique()->sort()->values()->all())->toBe(collect([
        'at_inspection', 'awaiting_balance', 'awaiting_delivery', 'cancelled_buyer_nopay', 'cancelled_inspection', 'cancelled_seller',
        'cancelled_staff', 'completed', 'ready_to_collect', 'weight_adjust_pending',
    ])->sort()->values()->all());

    foreach ($orders as $order) {
        $deposit = bcadd((string) $order->buyRequest->deposit_amount, '0', 4);
        $label = "{$order->order_ref} ({$order->state->value})";
        $held = reconSum(array_merge(...array_map(fn ($k) => reconLines($order, $k), ['deposit_hold', 'deposit_release', 'deposit_forfeit', 'balance_payment'])),
            fn ($l) => $l['kind'] === AccountKind::CUST_HELD->value && $l['party'] === 'buyer');

        expect(reconTxns($order, 'deposit_hold'))->toBe(1, $label);

        switch (true) {
            case in_array($order->state, [OrderState::AWAITING_DELIVERY, OrderState::AT_INSPECTION, OrderState::WEIGHT_ADJUST_PENDING, OrderState::AWAITING_BALANCE], true):
                expect($held)->toBe($deposit, $label)
                    ->and(reconTxns($order, 'deposit_release') + reconTxns($order, 'deposit_forfeit') + reconTxns($order, 'balance_payment'))->toBe(0, $label);
                break;

            case $order->state === OrderState::CANCELLED_BUYER_NOPAY:
                $forfeit = reconLines($order, 'deposit_forfeit');
                expect(reconTxns($order, 'deposit_forfeit'))->toBe(1, $label)
                    ->and(reconSum($forfeit, fn ($l) => $l['party'] === 'buyer'))->toBe('-'.$deposit, $label)
                    ->and(reconSum($forfeit, fn ($l) => $l['party'] !== 'buyer'))->toBe($deposit, $label)
                    ->and($held)->toBe('0.0000', $label)
                    ->and(reconTxns($order, 'deposit_release'))->toBe(0, $label);
                break;

            case $order->state->isCancelled():
                $release = reconLines($order, 'deposit_release');
                expect(reconTxns($order, 'deposit_release'))->toBe(1, $label)
                    ->and(reconSum($release, fn ($l) => $l['kind'] === AccountKind::CUST_AVAILABLE->value && $l['party'] === 'buyer'))->toBe($deposit, $label)
                    ->and($held)->toBe('0.0000', $label)
                    ->and(reconTxns($order, 'balance_payment'))->toBe(0, $label);
                break;

            default: // ready_to_collect, completed
                $pay = reconLines($order, 'balance_payment');
                expect(reconTxns($order, 'balance_payment'))->toBe(1, $label)
                    ->and(reconSum($pay, fn ($l) => $l['kind'] === AccountKind::ESCROW->value))->toBe('0.0000', $label)
                    ->and(reconSum($pay, fn ($l) => $l['kind'] === AccountKind::CUST_HELD->value && $l['party'] === 'buyer'))->toBe('-'.$deposit, $label)
                    ->and(reconSum($pay, fn ($l) => $l['party'] === 'buyer'))->toBe('-'.bcadd((string) $order->final_buyer_total, '0', 4), $label)
                    ->and(reconSum($pay, fn ($l) => $l['party'] === 'seller'))->toBe(bcadd((string) $order->seller_proceeds, '0', 4), $label)
                    ->and($held)->toBe('0.0000', $label);
        }
    }

    DatabaseActor::push('maintenance');
    try {
        expect(bcadd((string) DB::table('ledger_posting')->sum('amount'), '0', 4))->toBe('0.0000')
            ->and(bcadd((string) DB::table('ledger_posting')->where('account_id', Account::internal(AccountKind::ESCROW))->sum('amount'), '0', 4))->toBe('0.0000');
    } finally {
        DatabaseActor::pop();
    }
});
