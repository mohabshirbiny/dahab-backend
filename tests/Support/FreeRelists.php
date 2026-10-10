<?php

namespace Tests\Support;

use App\Enums\PieceCategory;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 018 tests (after collection: the free relist
 * and the rating). An order is always taken to `completed` through the real
 * endpoints — receive, result, pay, staff handover — never by writing rows.
 * Names are prefixed `ac018` so they never collide with another suite's.
 */
final class FreeRelists
{
    /**
     * A live piece taken all the way to a staff handover: the order is
     * `completed`, the collection stamped and, with the setting above 0 and
     * the branch's hours known, the free-relist window stored.
     *
     * @param  array<string, mixed>|null  $result  the inspection result; by default the right one for the category
     */
    public static function ac018CollectedOrder(TestCase $test, ?Listing $listing = null, ?array $result = null, ?Customer $buyer = null): Order
    {
        Orders::workedPrices();
        $listing ??= Orders::ring();

        $order = Orders::accepted($test, $listing, '200000', $buyer);
        Orders::staff($test, SeedRole::OPERATIONS);
        Orders::receive($test, $order)->assertOk();
        Orders::staff($test, SeedRole::IGI_BRANCH);
        Orders::result($test, $order, $result ?? self::ac018DefaultResult($listing))->assertCreated();
        $order->refresh();

        $code = Orders::pay($test, Orders::buyer($order), $order)->assertOk()->json('data.collection_code');
        Orders::staff($test, SeedRole::IGI_BRANCH, $order->branch_id);
        $test->postJson(Orders::STAFF_URL."/{$order->order_id}/handover", ['code' => $code], Listings::key())->assertOk();

        return $order->refresh();
    }

    /** @return array<string, mixed> */
    public static function ac018DefaultResult(Listing $listing): array
    {
        return match ($listing->category) {
            PieceCategory::DIAMOND => ['measured_stone_grade' => 'VS1 G'],
            PieceCategory::GOLD_WITH_DIAMOND => ['measured_karat' => 21, 'measured_weight_g' => '6.100'],
            default => ['measured_karat' => 21, 'measured_weight_g' => '10.000'],
        };
    }

    /**
     * The body the buyer sends to relist: the field the category needs, a
     * description and the current ownership declaration.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public static function ac018RelistPayload(Order $order, array $over = []): array
    {
        $category = Listing::query()->findOrFail($order->listing_id)->category;
        $price = $category === PieceCategory::GOLD
            ? ['making_charge_per_g' => '250.00']
            : ['asking_price' => $category === PieceCategory::DIAMOND ? '130000.00' : '90000.00'];

        return array_merge($price, [
            'description' => 'Back on the market, same piece.',
            'ownership_legal_doc_id' => Listings::declarationId(),
        ], $over);
    }

    /** @param  array<string, mixed>|null  $body */
    public static function ac018Relist(TestCase $test, Customer $buyer, Order $order, ?array $body = null, ?string $key = null): TestResponse
    {
        return Listings::as($test, $buyer)->postJson(
            Orders::CUSTOMER_URL."/{$order->order_id}/free-relist",
            $body ?? self::ac018RelistPayload($order),
            Listings::key($key),
        );
    }

    /** @param  array<string, mixed>  $body */
    public static function ac018Rate(TestCase $test, Customer $customer, Order $order, array $body, ?string $key = null): TestResponse
    {
        return Listings::as($test, $customer)->postJson(
            Orders::CUSTOMER_URL."/{$order->order_id}/rating",
            $body,
            Listings::key($key),
        );
    }

    /** Sell the relisted listing the way any piece is sold: a request, the seller's accept, receive, result, pay. */
    public static function ac018SettleRelisted(TestCase $test, Listing $relisted, ?array $result = null): Order
    {
        Orders::workedPrices();
        $order = Orders::accepted($test, $relisted, '200000');
        Orders::staff($test, SeedRole::OPERATIONS);
        Orders::receive($test, $order)->assertOk();
        Orders::staff($test, SeedRole::IGI_BRANCH);
        Orders::result($test, $order, $result ?? self::ac018DefaultResult($relisted))->assertCreated();
        $order->refresh();
        Orders::pay($test, Orders::buyer($order), $order)->assertOk();

        return $order->refresh();
    }

    /** Suspend a customer the way staff do (the database wants a reason and a staff member). */
    public static function ac018Suspend(Customer $customer): Customer
    {
        DatabaseActor::elevate('maintenance', fn () => DB::table('customer')->where('customer_id', $customer->customer_id)->update([
            'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
            'suspended_reason' => 'off_platform_dealing', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
        ]));

        return $customer->refresh();
    }

    /** Close an account (final); nothing else about the customer changes. */
    public static function ac018Close(Customer $customer): Customer
    {
        DatabaseActor::elevate('maintenance', fn () => DB::table('customer')->where('customer_id', $customer->customer_id)->update([
            'status' => 'closed', 'closed_at' => now(), 'closed_reason' => 'finished',
        ]));

        return $customer->refresh();
    }

    public static function ac018Window(Order $order): ?string
    {
        return OrderCollection::query()->where('order_id', $order->order_id)->value('free_relist_until');
    }
}
