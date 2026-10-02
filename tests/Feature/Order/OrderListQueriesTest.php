<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 T081 (quality gates): the order lists load their relations in a
// fixed number of queries — the count does not grow with the rows (no N+1).

/** Queries one GET runs. */
function listQueries(Closure $get): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $get()->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('lists orders, results, the work list and buy requests in a fixed number of queries', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $buyer = BuyRequests::funded('1000000');

    $grow = function (int $n) use ($buyer) {
        foreach (range(1, $n) as $_) {
            Orders::inspected($this, Orders::accepted($this, null, '0', $buyer), '10.000');
        }
    };

    $measure = function () use ($buyer) {
        Orders::staff($this, SeedRole::OPERATIONS);

        return [
            'staff orders' => listQueries(fn () => $this->getJson(Orders::STAFF_URL.'?group=all')),
            'inspections' => listQueries(fn () => $this->getJson('/api/v1/dashboard/inspections')),
            'work list' => listQueries(fn () => $this->getJson(Orders::WORK_LIST_URL)),
            'buy requests' => listQueries(fn () => $this->getJson('/api/v1/dashboard/buy-requests?state=accepted')),
            'customer orders' => listQueries(fn () => Listings::as($this, Customer::query()->find($buyer->customer_id))->getJson(Orders::CUSTOMER_URL)),
        ];
    };

    $grow(2);
    $few = $measure();
    $grow(4);
    $many = $measure();

    expect($many)->toBe($few);
});
