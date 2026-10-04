<?php

use App\Enums\SeedRole;
use App\Models\BankMovement;
use App\Models\Compensation;
use App\Models\WalletAdjustment;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Finance;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 SC-001, SC-003, SC-005: after every finance outcome the ledger sums
// to zero, the bank's cash is −SUM(bank), each row matches its entry, the held
// lines sum to held_on_orders, and a closed day does not move.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('keeps every book balanced across compensation, adjustments, movements, holds and a close', function () {
    $buyer = BuyRequests::funded('200000');
    BuyRequests::queued($this, $buyer, Orders::ring());
    Orders::accepted($this, buyer: $buyer);
    $customer = Finance::customer('1000');

    Finance::staff($this, SeedRole::FINANCE);
    Finance::pay($this, $customer, '800')->assertCreated();
    Finance::record($this, 'capital_in', 'in', '500000')->assertCreated();
    Finance::record($this, 'bank_charge', 'out', '250')->assertCreated();
    Finance::record($this, 'own_transfer', 'out', '9000')->assertCreated();
    Finance::staff($this, SeedRole::CEO);
    Finance::adjust($this, $customer, 'debit', '300')->assertCreated();
    Finance::adjust($this, $buyer, 'credit', '50')->assertCreated();

    $rows = DatabaseActor::elevate('maintenance', fn () => [
        'comp' => Compensation::query()->get(), 'adj' => WalletAdjustment::query()->get(), 'mov' => BankMovement::query()->get(),
    ]);
    $lineOf = fn (?string $txn, string $kind) => DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting AS p')
        ->join('account AS a', 'a.account_id', '=', 'p.account_id')->where('p.ledger_txn_id', $txn)->where('a.kind', $kind)->sum('p.amount'));

    expect(Finance::globalSum())->toBe('0.0000')
        ->and(Finance::bankCash())->toBe(bcadd(bcsub('500000', '250', 4), '201000', 4))
        ->and(Finance::available($customer))->toBe('1500.0000');
    foreach ($rows['comp'] as $c) {
        expect(bcadd($lineOf($c->ledger_txn_id, 'cust_available'), '0', 4))->toBe(bcadd((string) $c->amount, '0', 4));
    }
    foreach ($rows['adj'] as $a) {
        expect(bcadd($lineOf($a->ledger_txn_id, 'cust_available'), '0', 4))->toBe($a->direction->signed(bcadd((string) $a->amount, '0', 4)));
    }
    foreach ($rows['mov'] as $m) {
        expect($m->ledger_txn_id === null ? '0.0000' : bcadd($lineOf($m->ledger_txn_id, 'bank'), '0', 4))
            ->toBe($m->ledger_txn_id === null ? '0.0000' : bcmul((string) $m->amount, '-1', 4));
    }

    $held = Listings::as($this, $buyer)->getJson('/api/v1/customer/me/wallet/held')->assertOk()->json('data.total');
    expect($held)->toBe(Listings::as($this, $buyer)->getJson('/api/v1/customer/me/wallet')->json('data.held_on_orders'));
});

it('keeps a closed day exactly as it was closed', function () {
    $yesterday = now('Africa/Cairo')->subDays(2)->toDateString();
    Finance::staff($this, SeedRole::FINANCE);
    $before = $this->getJson('/api/v1/dashboard/daily-close?date='.$yesterday)->assertOk()->json('data.books');
    Finance::close($this, $yesterday, $before['bank'])->assertOk();

    Finance::record($this, 'capital_in', 'in', '1000', ['occurred_on' => $yesterday])->assertCreated();
    Finance::pay($this, Finance::customer('0'), '100')->assertCreated();

    $after = $this->getJson('/api/v1/dashboard/daily-close?date='.$yesterday)->assertOk()->json('data.close');
    expect($after['books_bank'])->toBe($before['bank'])->and($after['customer_liability'])->toBe($before['customer_liability']);
});
