<?php

use App\Enums\SeedRole;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 SC-004, T050: after every dispute outcome, compensation, proxy
// collection and extension, the money adds up exactly.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('keeps the ledger balanced and every ending carrying exactly its money', function () {
    $resolve = function ($order, array $body, SeedRole $as = SeedRole::CEO) {
        $dispute = Disputes::opened($this, $order);
        Orders::staff($this, $as);
        Disputes::resolve($this, $dispute, $body)->assertOk();
    };
    $comp = fn (string $party, string $amount) => ['compensation' => ['party' => $party, 'amount' => $amount, 'reason' => 'goodwill', 'note' => 'For the trouble caused.']];

    // Resume from each state, with and without compensation.
    $resume = [
        Disputes::atInspection($this), Disputes::deciding($this), Disputes::awaitingBalance($this), Disputes::readyToCollect($this),
    ];
    foreach ($resume as $i => $order) {
        $resolve($order, $i % 2 ? $comp('seller', '250.5') : []);
    }
    // Against the sale from each pre-payment state.
    $against = [Disputes::atInspection($this), Disputes::deciding($this), Disputes::awaitingBalance($this)];
    foreach ($against as $order) {
        $resolve($order, ['outcome' => 'against_sale'] + $comp('buyer', '100'));
    }
    // A resumed order then paid; a paid one collected by a proxy; an extended one.
    Orders::pay($this, Orders::buyer($resume[2]), $resume[2])->assertOk();
    Disputes::nameProxy($this, $resume[3])->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $code = DatabaseActor::elevate('maintenance', fn () => DB::table('collection')->where('order_id', $resume[3]->order_id)->value('code_encrypted'));
    $this->postJson(Orders::STAFF_URL."/{$resume[3]->order_id}/handover",
        ['code' => decrypt($code, false), 'collector' => 'proxy', 'proxy_id_checked' => true], Listings::key())->assertOk();
    $extended = Orders::accepted($this);
    Disputes::askMoreTime($this, $extended)->assertCreated();
    Orders::staff($this, SeedRole::OPERATIONS);
    $request = DatabaseActor::elevate('maintenance', fn () => DB::table('order_extension_request')->value('request_id'));
    $this->postJson(Disputes::REQUESTS_URL."/{$request}/accept", ['hours' => 6, 'note' => 'Extended for the reconciliation.'], Listings::key())->assertOk();

    DatabaseActor::elevate('maintenance', function () use ($against, $resume) {
        // Every entry balanced; the whole ledger sums to zero.
        expect(DB::table('ledger_posting')->select('ledger_txn_id')->groupBy('ledger_txn_id')->havingRaw('SUM(amount) <> 0')->count())->toBe(0)
            ->and((string) DB::table('ledger_posting')->sum('amount'))->toBe('0.0000');

        // Against the sale: one deposit release of exactly the deposit, no settlement.
        foreach ($against as $order) {
            $deposit = (string) DB::table('buy_request')->where('buy_request_id', $order->buy_request_id)->value('deposit_amount');
            $releases = DB::table('ledger_transaction')->where('buy_request_id', $order->buy_request_id)->where('event_kind', 'deposit_release')->pluck('ledger_txn_id');
            $toAvailable = DB::table('ledger_posting as p')->join('account as a', 'a.account_id', '=', 'p.account_id')
                ->whereIn('p.ledger_txn_id', $releases)->where('a.kind', 'cust_available')->sum('p.amount');
            expect($releases)->toHaveCount(1)
                ->and(bccomp((string) $toAvailable, $deposit, 4))->toBe(0)
                ->and(DB::table('ledger_transaction')->where('order_id', $order->order_id)->where('event_kind', 'balance_payment')->count())->toBe(0)
                ->and(DB::table('order')->where('order_id', $order->order_id)->value('state'))->toBe('cancelled_inspection');
        }

        // Every compensation row has exactly its entry; nothing else is a compensation.
        $rows = DB::table('compensation')->get();
        expect($rows)->toHaveCount(5)
            ->and(DB::table('ledger_transaction')->where('event_kind', 'compensation')->count())->toBe(5);
        foreach ($rows as $row) {
            $credit = DB::table('ledger_posting as p')->join('account as a', 'a.account_id', '=', 'p.account_id')
                ->where('p.ledger_txn_id', $row->ledger_txn_id)->where('a.customer_id', $row->customer_id)->where('a.kind', 'cust_available')
                ->value('p.amount');
            expect(bccomp((string) $credit, (string) $row->amount, 4))->toBe(0);
        }

        // Paid orders: exactly one settlement each.
        foreach ([$resume[2], $resume[3]] as $order) {
            expect(DB::table('ledger_transaction')->where('order_id', $order->order_id)->where('event_kind', 'balance_payment')->count())->toBe(1);
        }

        // No customer account below zero.
        expect(DB::table('ledger_posting as p')->join('account as a', 'a.account_id', '=', 'p.account_id')
            ->whereNotNull('a.customer_id')->select('p.account_id')->groupBy('p.account_id')->havingRaw('SUM(p.amount) < 0')->count())->toBe(0);
    });
});
