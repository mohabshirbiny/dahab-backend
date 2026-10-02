<?php

namespace Tests\Support;

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\GoldPrice;
use App\Models\KaratPriceAdjustment;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 012 order tests. An order is always reached
 * through the API (a real request, its hold, the seller's accept, the
 * staff's receive, the inspector's result, the buyer's payment) — never by
 * writing rows: the database refuses a move without its history and money.
 */
final class Orders
{
    public const CUSTOMER_URL = '/api/v1/customer/me/orders';

    public const STAFF_URL = '/api/v1/dashboard/orders';

    public const WORK_LIST_URL = '/api/v1/dashboard/inspections/work-list';

    /**
     * Part 3 §3.3: bid = ask = 5,994 and 21K adjustments ∓13.125 fixed, so
     * sellers_get(21K) = 5,236.875 and buyers_pay(21K) = 5,263.125.
     */
    public static function workedPrices(): GoldPrice
    {
        $price = Listings::goldPrice('5994', '5994');
        KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'buy')->update(['kind' => 'fixed', 'value' => '-13.125']);
        KaratPriceAdjustment::query()->where('karat_code', 21)->where('side', 'sell')->update(['kind' => 'fixed', 'value' => '13.125']);

        return $price;
    }

    /** A live 21K ring of 10.000 g at 300 EGP/g making (the §3.3 ring). */
    public static function ring(?Customer $seller = null, array $attributes = []): Listing
    {
        $seller ??= Customer::factory()->verified()->create();

        return Listing::factory()->withPhotos(2)->live()->create(array_merge([
            'seller_id' => $seller->customer_id,
            'karat_code' => 21,
            'stated_weight_g' => '10.000',
            'making_charge_per_g' => '300.00',
        ], $attributes));
    }

    /**
     * A buyer requests and the seller accepts: the order awaiting delivery.
     * The buyer is funded with `$funds` (enough for the deposit and, by
     * default, the whole price).
     */
    public static function accepted(TestCase $test, ?Listing $listing = null, string $funds = '60000', ?Customer $buyer = null): Order
    {
        $listing ??= self::ring();
        $buyer ??= BuyRequests::funded($funds);
        $request = BuyRequests::queued($test, $buyer, $listing);
        $seller = Customer::query()->findOrFail($listing->seller_id);

        BuyRequests::accept($test, $seller, $listing, $request, BuyRequests::branchOf($listing))->assertCreated();

        return Order::query()->where('buy_request_id', $request->buy_request_id)->firstOrFail();
    }

    /** Act as a staff member of `$role`, optionally assigned to a branch. */
    public static function staff(TestCase $test, SeedRole $role, ?int $branchId = null): Staff
    {
        $staff = Listings::actAsStaff($test, $role);
        if ($branchId !== null) {
            DatabaseActor::elevate('maintenance', fn () => DB::table('staff')->where('staff_id', $staff->staff_id)->update(['branch_id' => $branchId]));
            $staff->refresh();
        }

        return $staff;
    }

    public static function receive(TestCase $test, Order $order, ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF_URL."/{$order->order_id}/receive", [], Listings::key($key));
    }

    /** @param  array<string, mixed>  $body */
    public static function result(TestCase $test, Order $order, array $body, ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF_URL."/{$order->order_id}/inspection-results", $body, Listings::key($key));
    }

    /** Receive and record a result as an Operations / IGI pair with no branch. */
    public static function inspected(TestCase $test, Order $order, string $weight = '10.000', int $karat = 21): Order
    {
        self::staff($test, SeedRole::OPERATIONS);
        self::receive($test, $order)->assertOk();
        self::staff($test, SeedRole::IGI_BRANCH);
        self::result($test, $order, ['measured_karat' => $karat, 'measured_weight_g' => $weight])->assertCreated();

        return $order->refresh();
    }

    public static function pay(TestCase $test, Customer $buyer, Order $order, ?string $key = null): TestResponse
    {
        return Listings::as($test, $buyer)->postJson(self::CUSTOMER_URL."/{$order->order_id}/pay-balance", [], Listings::key($key));
    }

    public static function cancel(TestCase $test, Customer $seller, Order $order, ?string $key = null): TestResponse
    {
        return Listings::as($test, $seller)->postJson(self::CUSTOMER_URL."/{$order->order_id}/cancel", [], Listings::key($key));
    }

    public static function show(TestCase $test, Customer $customer, Order $order): TestResponse
    {
        return Listings::as($test, $customer)->getJson(self::CUSTOMER_URL."/{$order->order_id}");
    }

    public static function sweep(): int
    {
        return Artisan::call('orders:sweep');
    }

    public static function buyer(Order $order): Customer
    {
        return Customer::query()->findOrFail($order->buyer_id);
    }

    public static function seller(Order $order): Customer
    {
        return Customer::query()->findOrFail($order->seller_id);
    }

    /** An internal account's balance (sum of its lines). */
    public static function internal(AccountKind $kind): string
    {
        return DatabaseActor::elevate('maintenance', fn () => bcadd((string) DB::table('ledger_posting')
            ->where('account_id', Account::internal($kind))->sum('amount'), '0', 4));
    }

    /** @return list<object{event_kind: string, kind: string, customer_id: string|null, amount: string}> */
    public static function lines(Order $order, string $eventKind): array
    {
        return DatabaseActor::elevate('maintenance', fn () => DB::table('ledger_transaction as t')
            ->join('ledger_posting as p', 'p.ledger_txn_id', '=', 't.ledger_txn_id')
            ->join('account as a', 'a.account_id', '=', 'p.account_id')
            ->where('t.order_id', $order->order_id)->where('t.event_kind', $eventKind)
            ->orderBy('p.posting_id')
            ->get(['t.event_kind', 'a.kind', 'a.customer_id', DB::raw('p.amount::text AS amount')])->all());
    }
}
