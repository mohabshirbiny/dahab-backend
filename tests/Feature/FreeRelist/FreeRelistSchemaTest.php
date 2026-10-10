<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\FreeRelists;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 018 data-model.md: what the database itself refuses, whatever the
// application does. Direct SQL; each attempt runs in a savepoint and forces
// the deferred checks.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

/** The SQLSTATE `$work` fails with (deferred checks forced), or null. */
function ac018SqlState(Closure $work): ?string
{
    try {
        DB::transaction(function () use ($work) {
            $work();
            BuyRequests::checkNow();
        });
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('keeps the free-relist window write-once and set only with the handover', function () {
    $collected = FreeRelists::ac018CollectedOrder($this);
    // Paid, with its collection row, but not handed over yet.
    $open = Orders::inspected($this, Orders::accepted($this, Orders::ring(), '200000'), '10.000');
    Orders::pay($this, Orders::buyer($open), $open)->assertOk();

    expect(FreeRelists::ac018Window($collected))->not->toBeNull()
        ->and(ac018SqlState(fn () => DB::table('collection')->where('order_id', $collected->order_id)
            ->update(['free_relist_until' => DB::raw("free_relist_until + interval '1 hour'")])))->toBe('DH016')
        ->and(ac018SqlState(fn () => DB::table('collection')->where('order_id', $collected->order_id)->update(['free_relist_until' => null])))->toBe('DH016')
        // An order not collected yet cannot be given a window.
        ->and(ac018SqlState(fn () => DB::table('collection')->where('order_id', $open->order_id)
            ->update(['free_relist_until' => DB::raw("now() + interval '12 hours'")])))->toBe('DH016');
});

it('only lets a listing link to a completed order of its own buyer, inside the window, once', function () {
    $collected = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($collected);
    $stranger = Customer::factory()->verified()->create();
    $open = Orders::accepted($this, Orders::ring());

    $make = fn (Customer $seller, Order $from) => fn () => Listing::factory()->create([
        'seller_id' => $seller->customer_id,
        'relisted_from_order_id' => $from->order_id,
    ]);

    // Not completed; not the buyer; and a good one.
    expect(ac018SqlState($make($buyer, $open)))->toBe('DH004')
        ->and(ac018SqlState($make($stranger, $collected)))->toBe('DH004')
        ->and(ac018SqlState($make($buyer, $collected)))->toBeNull();
});

it('refuses a second listing for the same order', function () {
    $collected = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($collected);
    $first = Listing::factory()->create(['seller_id' => $buyer->customer_id, 'relisted_from_order_id' => $collected->order_id]);

    expect(ac018SqlState(fn () => Listing::factory()->create(['seller_id' => $buyer->customer_id, 'relisted_from_order_id' => $collected->order_id])))->toBe('23505')
        ->and($first->relisted_from_order_id)->toBe($collected->order_id);
});

it('never changes the link, and lets a draft go live only with one', function () {
    $collected = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($collected);
    $linked = Listing::factory()->create(['seller_id' => $buyer->customer_id, 'relisted_from_order_id' => $collected->order_id]);
    $plain = Listing::factory()->draft()->create();

    $history = fn (Listing $l) => DB::table('listing_state_change')->insert([
        'listing_id' => $l->listing_id, 'from_state' => 'draft', 'to_state' => 'live', 'actor_customer_id' => $l->seller_id,
    ]);

    expect(ac018SqlState(fn () => DB::table('listing')->where('listing_id', $linked->listing_id)->update(['relisted_from_order_id' => null])))->toBe('DH004')
        ->and(ac018SqlState(function () use ($plain, $history) {
            DB::table('listing')->where('listing_id', $plain->listing_id)->update(['state' => 'live']);
            $history($plain);
        }))->toBe('DH004')
        ->and(ac018SqlState(function () use ($linked, $history) {
            DB::table('listing')->where('listing_id', $linked->listing_id)->update(['state' => 'live']);
            $history($linked);
        }))->toBeNull();
});

it('holds one immutable rating per party, between 1 and 5 stars', function () {
    $order = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);
    $row = fn (string $role, string $customer, array $over = []) => array_merge([
        'order_id' => $order->order_id, 'party_role' => $role, 'customer_id' => $customer, 'stars' => 4, 'note' => null,
    ], $over);

    expect(ac018SqlState(fn () => DB::table('order_rating')->insert($row('buyer', $buyer->customer_id))))->toBeNull()
        // A second rating by the same party, stars out of range and a note too long.
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('buyer', $buyer->customer_id))))->toBe('23505')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('seller', $seller->customer_id, ['stars' => 6]))))->toBe('23514')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('seller', $seller->customer_id, ['stars' => 0]))))->toBe('23514')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('seller', $seller->customer_id, ['note' => str_repeat('a', 501)]))))->toBe('23514')
        // The author must be the party they rate as.
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('seller', $buyer->customer_id))))->toBe('DH016')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row('seller', $seller->customer_id))))->toBeNull();

    // A rating never changes or goes away.
    expect(ac018SqlState(fn () => DB::table('order_rating')->where('order_id', $order->order_id)->update(['stars' => 1])))->toBe('DH016')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->where('order_id', $order->order_id)->delete()))->toBe('DH016');
});

it('refuses a rating of an unsettled order, and a buyer before completion', function () {
    Orders::workedPrices();
    $open = Orders::accepted($this, Orders::ring());
    $paid = Orders::inspected($this, Orders::accepted($this, Orders::ring()), '10.000');
    Orders::pay($this, Orders::buyer($paid), $paid)->assertOk();

    $row = fn (Order $o, string $role) => [
        'order_id' => $o->order_id, 'party_role' => $role,
        'customer_id' => $role === 'buyer' ? $o->buyer_id : $o->seller_id, 'stars' => 5,
    ];

    expect(ac018SqlState(fn () => DB::table('order_rating')->insert($row($open, 'seller'))))->toBe('DH016')
        // Paid and ready to collect: the seller may, the buyer not yet.
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row($paid, 'buyer'))))->toBe('DH016')
        ->and(ac018SqlState(fn () => DB::table('order_rating')->insert($row($paid, 'seller'))))->toBeNull();
});

it('has forced row-level security on order_rating', function () {
    $flags = DB::selectOne("SELECT relrowsecurity AS on_, relforcerowsecurity AS forced FROM pg_class WHERE relname = 'order_rating'");

    expect($flags->on_)->toBeTrue()->and($flags->forced)->toBeTrue();
});
