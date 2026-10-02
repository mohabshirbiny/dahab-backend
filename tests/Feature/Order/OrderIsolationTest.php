<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-001, research R2 (Constitution II): every table of the order's
// life has forced row-level security; a customer sees only the rows of their
// own orders, decided by the database, not by a WHERE clause.

const ORDER_TABLES = [
    'order_state_change', 'order_branch_change', 'order_deadline_extension', 'seller_cancellation',
    'inspection_result', 'settlement_decision', 'collection', 'seller_return',
];

it('forces row-level security with a policy on every new table', function () {
    foreach (ORDER_TABLES as $table) {
        $row = DB::selectOne('SELECT relrowsecurity AND relforcerowsecurity AS forced FROM pg_class WHERE relname = ? AND relkind = \'r\'', [$table]);

        expect($row?->forced)->toBeTrue("{$table} is not forced")
            ->and(DB::table('pg_policies')->where('tablename', $table)->count())->toBeGreaterThan(0);
    }
});

it('shows a customer only the rows of their own orders', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $order = Orders::accepted($this);
    Orders::inspected($this, $order);
    $stranger = BuyRequests::funded('1000');

    $count = function (Customer $c) {
        DatabaseActor::push('customer', $c->customer_id);
        try {
            return collect(ORDER_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->filter()->all();
        } finally {
            DatabaseActor::pop();
        }
    };

    expect($count(Orders::buyer($order)))->toHaveKeys(['order_state_change', 'inspection_result'])
        ->and($count(Orders::seller($order)))->toHaveKeys(['order_state_change', 'inspection_result'])
        ->and($count($stranger))->toBe([]);
});

it('refuses a customer writing staff rows', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $order = Orders::accepted($this);
    Orders::staff($this, SeedRole::OPERATIONS);

    DatabaseActor::push('customer', $order->seller_id);
    try {
        expect(fn () => DB::transaction(fn () => DB::table('inspection_result')->insert([
            'order_id' => $order->order_id, 'branch_id' => $order->branch_id, 'inspected_by' => SystemActor::id(),
            'karat_mismatch' => false, 'outcome' => 'pass',
        ])))->toThrow(QueryException::class);
    } finally {
        DatabaseActor::pop();
    }
});
