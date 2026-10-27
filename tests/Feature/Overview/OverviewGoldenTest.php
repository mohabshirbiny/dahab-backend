<?php

use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\OrderState;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Ledger;
use Tests\Support\Orders;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Performance rewrite of the Overview figures: the new SQL must give exactly
// what the original statements gave. The reference figures below are the
// original queries (the solvency_check / ledger_global_zero views and the
// unbounded held CTE), kept here only to compare against.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();

    // Orders in several states: two waiting for delivery, one paid, one disputed, one cancelled.
    $this->waiting = Orders::accepted($this);
    Orders::accepted($this);
    Disputes::readyToCollect($this);
    Disputes::opened($this, Disputes::awaitingBalance($this));
    $cancelled = Orders::accepted($this);
    Orders::cancel($this, Orders::seller($cancelled), $cancelled)->assertOk();

    // Plain wallet money, held money, bank capital and a withdrawal not yet released.
    $mona = Customer::factory()->verified()->create();
    Ledger::topUp($mona, '1234.5678');
    Ledger::hold($mona, '200.0001');
    Ledger::post(LedgerEventKind::EXTERNAL_BANK_MOVEMENT, null, [
        [Account::internal(AccountKind::BANK), '-5000'],
        [Account::internal(AccountKind::EXTERNAL_EQUITY), '5000'],
    ], staffId: $actor = Staff::factory()->create()->staff_id);
    Withdrawals::requested($this, '1000', '1000');

    // Earnings either side of the start of this Cairo month.
    $start = CarbonImmutable::now('Africa/Cairo')->startOfMonth();
    foreach ([[$start->subSecond(), '-111.11'], [$start, '-22.22']] as [$when, $amount]) {
        Ledger::postAt($when->format('Y-m-d H:i:s'), LedgerEventKind::EXTERNAL_BANK_MOVEMENT, null, [
            [Account::internal(AccountKind::DAHAB_COMMISSION), $amount],
            [Account::internal(AccountKind::EXTERNAL_EQUITY), ltrim($amount, '-')],
        ], staffId: $actor);
    }

    IdentityDocument::factory()->create(['customer_id' => Customer::factory()->pendingVerification()->create()->customer_id]);

    expect($cancelled->refresh()->state->value)->toStartWith('cancelled');
});

it('gives the same wallet figures as the original views', function () {
    $ref = DB::selectOne("
        SELECT
          (SELECT COALESCE(SUM(p.amount), 0) FROM ledger_posting p JOIN account a ON a.account_id = p.account_id WHERE a.kind = 'cust_available')::numeric(18,4)::text AS available,
          (SELECT COALESCE(SUM(p.amount), 0) FROM ledger_posting p JOIN account a ON a.account_id = p.account_id WHERE a.kind = 'cust_held')::numeric(18,4)::text      AS held,
          s.owed_to_customers::numeric(18,4)::text AS total_owed,
          s.bank_balance::numeric(18,4)::text      AS bank,
          s.headroom::numeric(18,4)::text          AS headroom,
          (SELECT must_be_zero FROM ledger_global_zero)::numeric(18,4)::text AS system_total
        FROM solvency_check s
    ");
    $pending = (string) DB::selectOne("SELECT COALESCE(SUM(amount), 0)::numeric(18,4)::text AS p FROM withdrawal WHERE state IN ('requested', 'under_review')")->p;

    Finance::staff($this, SeedRole::CEO);
    $this->getJson('/api/v1/dashboard/wallets/overview')->assertOk()->assertExactJson(['data' => [
        'available' => $ref->available,
        'held' => $ref->held,
        'held_on_orders' => bcsub($ref->held, $pending, 4),
        'pending_withdrawals' => $pending,
        'total_owed' => $ref->total_owed,
        'bank' => $ref->bank,
        'headroom' => $ref->headroom,
        'system_total' => $ref->system_total,
    ]]);
    expect(bccomp($ref->held, '0', 4))->toBe(1)->and($ref->system_total)->toBe('0.0000');
});

it('gives the same orders, earnings and waiting counts as the original statements', function () {
    $open = array_map(fn (OrderState $s) => $s->value, OrderState::open());
    $since = CarbonImmutable::now()->subDays(30);
    $orders = collect(DB::select("
        WITH held AS (
          SELECT t.buy_request_id, SUM(p.amount) AS amount
          FROM ledger_posting p
          JOIN account a ON a.account_id = p.account_id AND a.kind = 'cust_held'
          JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
          WHERE t.buy_request_id IS NOT NULL
          GROUP BY t.buy_request_id
        ), picked AS (
          SELECT CASE WHEN o.state::text LIKE 'cancelled%' THEN 'cancelled' ELSE o.state::text END AS state,
                 o.locked_total_price, COALESCE(h.amount, 0) AS held
          FROM \"order\" o LEFT JOIN held h ON h.buy_request_id = o.buy_request_id
          WHERE o.state::text = ANY (?::text[])
             OR (o.state = 'completed' AND o.completed_at >= ?)
             OR (o.state::text LIKE 'cancelled%' AND (SELECT max(sc.changed_at) FROM order_state_change sc WHERE sc.order_id = o.order_id) >= ?)
        )
        SELECT state, count(*) AS n, SUM(locked_total_price)::numeric(18,4)::text AS value, SUM(held)::numeric(18,4)::text AS held
        FROM picked GROUP BY state
    ", ['{'.implode(',', $open).'}', $since, $since]))->keyBy('state');

    $earn = DB::selectOne("
        SELECT COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_commission'), 0)::numeric(18,4)::text AS commission,
               COALESCE(SUM(p.amount) FILTER (WHERE a.kind = 'dahab_spread'), 0)::numeric(18,4)::text     AS spread
        FROM ledger_posting p
        JOIN account a ON a.account_id = p.account_id
        JOIN ledger_transaction t ON t.ledger_txn_id = p.ledger_txn_id
        WHERE a.kind IN ('dahab_commission', 'dahab_spread') AND t.created_at >= ?
    ", [CarbonImmutable::now('Africa/Cairo')->startOfMonth()]);

    Finance::staff($this, SeedRole::CEO);
    $data = $this->getJson('/api/v1/dashboard/overview')->assertOk()->json('data');

    $got = collect($data['orders'])->keyBy('state');
    expect($got->keys()->sort()->values()->all())->toBe($orders->keys()->sort()->values()->all())
        ->and($got->keys()->all())->toContain('awaiting_delivery', 'ready_to_collect', 'disputed', 'cancelled');
    foreach ($orders as $state => $row) {
        expect($got[$state])->toBe(['state' => $state, 'count' => (int) $row->n, 'value' => $row->value, 'held_now' => $row->held]);
    }
    expect(bccomp($got['awaiting_delivery']['held_now'], '0', 4))->toBe(1);

    expect($data['earnings'])->toBe([
        'month' => CarbonImmutable::now('Africa/Cairo')->format('Y-m'),
        'commission' => $earn->commission,
        'spread' => $earn->spread,
        'total' => bcadd($earn->commission, $earn->spread, 4),
    ]);

    $counts = collect($data['needs_decision'])->groupBy('kind')->map(fn ($rows) => $rows->first()['waiting_of_kind'])->sortKeys()->all();
    expect($counts)->toBe([
        'dispute' => DB::table('dispute')->where('state', '<>', 'resolved')->count(),
        'identity_document' => DB::table('identity_document')->where('status', 'pending')->count(),
        'withdrawal' => DB::table('withdrawal')->whereIn('state', ['requested', 'under_review'])->count(),
    ]);
    $since = array_column($data['needs_decision'], 'waiting_since');
    $sorted = $since;
    sort($sorted);
    expect($since)->toBe($sorted);
});
