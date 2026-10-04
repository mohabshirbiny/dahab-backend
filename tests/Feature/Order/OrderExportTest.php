<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 FR-018: the Orders list as CSV, with the list's filters, audited.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

/** @return list<list<string>> data rows of a CSV export */
function orderCsvRows(string $csv): array
{
    $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

    return array_map(fn (string $l) => str_getcsv($l, escape: ''), array_slice($lines, 1));
}

it('exports exactly the rows the list shows across every page, with the held amount, audited', function () {
    $first = Orders::accepted($this);
    $second = Orders::accepted($this);
    $closed = Orders::accepted($this);
    Orders::cancel($this, Orders::seller($closed), $closed)->assertOk();

    $viewer = Finance::staff($this, SeedRole::OPERATIONS);
    $listed = collect($this->getJson('/api/v1/dashboard/orders?group=waiting_seller&per_page=1')->json('data'))
        ->merge($this->getJson('/api/v1/dashboard/orders?group=waiting_seller&per_page=1&cursor='
            .$this->getJson('/api/v1/dashboard/orders?group=waiting_seller&per_page=1')->json('meta.next_cursor'))->json('data'))
        ->pluck('order_ref')->all();

    $response = $this->get('/api/v1/dashboard/orders/export?group=waiting_seller')->assertOk()
        ->assertHeader('X-Export-Truncated', 'false')->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $rows = orderCsvRows((string) $response->getContent());
    $deposit = bcadd((string) DB::table('buy_request')->where('buy_request_id', $first->buy_request_id)->value('deposit_amount'), '0', 4);

    expect(array_column($rows, 0))->toBe($listed)->toEqualCanonicalizing([$first->order_ref, $second->order_ref])
        ->and($rows[1][10])->toBe($deposit);

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'order.list_exported')->sole());
    expect($audit->actor_staff_id)->toBe($viewer->staff_id)
        ->and($audit->after_json['group'])->toBe('waiting_seller')->and($audit->after_json['rows'])->toBe(2);
});

it('applies the search and branch filters and needs order.view', function () {
    $order = Orders::accepted($this);
    Orders::accepted($this);

    Finance::staff($this, SeedRole::FINANCE);
    expect(orderCsvRows((string) $this->get('/api/v1/dashboard/orders/export?group=all&q='.$order->order_ref)->assertOk()->getContent()))->toHaveCount(1)
        ->and(orderCsvRows((string) $this->get('/api/v1/dashboard/orders/export?group=all&branch_id='.$order->branch_id)->assertOk()->getContent()))->toHaveCount(2)
        ->and(orderCsvRows((string) $this->get('/api/v1/dashboard/orders/export?group=all&branch_id=9999')->assertOk()->getContent()))->toHaveCount(0);

    Finance::staff($this, SeedRole::VERIFICATION);
    $this->get('/api/v1/dashboard/orders/export', ['Accept' => 'application/json'])->assertForbidden();
    $this->getJson('/api/v1/dashboard/orders/export?group=nowhere')->assertStatus(403);
});
