<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orders;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US8, D6: measured figures, no verdict, read-only, audited; IGI never sees the panel.

it('lists a person at the threshold with the counterparty figure, and leaves out someone below it', function () {
    Orders::workedPrices();
    DB::table('setting')->where('setting_key', 'flag.pattern_txn_threshold')->update(['value_numeric' => 2]);

    // Two orders between the same two accounts: both are at the threshold, each other's top counterparty.
    $first = Orders::accepted($this);
    $buyer = Orders::buyer($first);
    $seller = Orders::seller($first);
    Orders::accepted($this, Orders::ring($seller), buyer: $buyer);
    // Another account with a single order stays out.
    Orders::accepted($this);

    TopUps::actAsStaff($this, SeedRole::COO);
    $res = $this->getJson('/api/v1/dashboard/people-to-watch')->assertOk();

    $ids = collect($res->json('data'))->pluck('customer_id');
    expect($ids)->toContain($buyer->customer_id)->toContain($seller->customer_id)->toHaveCount(2)
        ->and($res->json('meta.threshold'))->toBe(2);

    $row = collect($res->json('data'))->firstWhere('customer_id', $seller->customer_id);
    expect($row['sold'])->toBe(2)
        // The counterparty counts completed orders only; these two are still at the branch.
        ->and($row['same_counterparty'])->toBeNull()
        ->and($row['not_paid'])->toBe(['n' => 0, 'of' => 0]);

    expect(AuditLog::query()->where('action', 'people_to_watch.viewed')->count())->toBe(1);
});

it('is for the CEO and the COO; Operations is refused', function () {
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);
    $this->getJson('/api/v1/dashboard/people-to-watch')->assertForbidden();
    $this->getJson('/api/v1/dashboard/people-to-watch/export')->assertForbidden();

    TopUps::actAsStaff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/people-to-watch/export')->assertOk();
});

it('writes nothing: no order changes by reading', function () {
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $before = $order->refresh()->state;

    TopUps::actAsStaff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/people-to-watch?from=2020-01-01')->assertOk();

    expect(Order::query()->findOrFail($order->order_id)->state)->toBe($before);
});
