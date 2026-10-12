<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Ledger;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T016 (Principle II): what the non-elevated `promo` scope, the `order` scope and a plain
// customer may read and write on the new tables (data-model §2.6, research R26).

/** Run `$work` as `$customer` in `$scope`, then put the test's maintenance frame back. */
function asScope(string $scope, Customer $customer, Closure $work): mixed
{
    DatabaseActor::push($scope, $customer->customer_id);
    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

function refusedState(Closure $work): ?string
{
    try {
        DB::transaction($work);
    } catch (QueryException $e) {
        return $e->errorInfo[0];
    }

    return null;
}

it('lets the promo scope read codes and its own rows, and nobody else\'s', function () {
    $me = Customer::factory()->verified()->create();
    $other = Customer::factory()->verified()->create();
    $mine = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $me->customer_id]);
    $theirs = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $other->customer_id]);
    $code = Promo::code();
    $myCredit = Promo::credit($me, $mine, ['promo_code' => $code->code]);
    $theirCredit = Promo::credit($other, $theirs);
    $theirReferral = Promo::referral($other);
    DB::table('listing_view')->insert(['listing_id' => $theirs->listing_id, 'day' => now()->toDateString(), 'viewer_hash' => 'h']);

    asScope('promo', $me, function () use ($code, $myCredit, $theirCredit, $theirReferral) {
        expect(DB::table('promo_code')->where('code', $code->code)->exists())->toBeTrue()
            ->and(DB::table('commission_credit')->pluck('credit_id')->all())->toBe([$myCredit->credit_id])
            ->and(DB::table('commission_credit')->where('credit_id', $theirCredit->credit_id)->exists())->toBeFalse()
            ->and(DB::table('referral')->where('referral_id', $theirReferral->referral_id)->exists())->toBeFalse()
            ->and(DB::table('listing_view')->count())->toBe(0)
            ->and(DB::table('first_sale_enablement')->count())->toBe(0);
    });
});

it('lets the promo scope insert only the caller\'s own credit and listing code', function () {
    $me = Customer::factory()->verified()->create();
    $other = Customer::factory()->verified()->create();
    $mine = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $me->customer_id]);
    $theirs = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $other->customer_id]);
    $code = Promo::code()->code;
    $row = fn (Customer $for, Listing $on) => [
        'customer_id' => $for->customer_id, 'source' => 'promo_code', 'kind' => 'waive', 'uses_total' => 1, 'uses_left' => 1,
        'listing_id' => $on->listing_id, 'promo_code' => $code,
    ];

    asScope('promo', $me, function () use ($row, $me, $other, $mine, $theirs) {
        expect(refusedState(fn () => DB::table('commission_credit')->insert($row($other, $theirs))))->toBe('42501')
            ->and(refusedState(fn () => DB::table('commission_credit')->insert($row($me, $mine))))->toBeNull();
    });
});

it('lets a buyer in the order scope spend only the credit of the seller of their own order', function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);
    $listing = Listing::query()->findOrFail($order->listing_id);

    $sellersCredit = Promo::credit($seller, $listing, ['kind' => 'percent']);
    $stranger = Customer::factory()->verified()->create();
    $strangerCredit = Promo::credit($stranger, Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $stranger->customer_id]));

    asScope('order', $buyer, function () use ($sellersCredit, $strangerCredit) {
        expect(DB::table('commission_credit')->where('credit_id', $sellersCredit->credit_id)->exists())->toBeTrue()
            ->and(DB::table('commission_credit')->where('credit_id', $strangerCredit->credit_id)->exists())->toBeFalse()
            // the stranger's credit is not even updatable: no row matches
            ->and(DB::table('commission_credit')->where('credit_id', $strangerCredit->credit_id)->update(['uses_left' => 0, 'status' => 'exhausted', 'ended_at' => now()]))->toBe(0);
    });

    // The seller still sees their own credit in their own scope, and the buyer cannot read it outside the order scope.
    asScope('customer', $seller, fn () => expect(DB::table('commission_credit')->where('credit_id', $sellersCredit->credit_id)->exists())->toBeTrue());
    asScope('customer', $buyer, fn () => expect(DB::table('commission_credit')->where('credit_id', $sellersCredit->credit_id)->exists())->toBeFalse());
});

it('never lets a customer read the use log, the enablements or the view counter', function () {
    $me = Customer::factory()->verified()->create();
    $listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $me->customer_id]);
    $code = Promo::code();
    DB::table('promo_code_use')->insert(['code' => $code->code, 'customer_id' => $me->customer_id, 'allowed' => false, 'block_reason' => 'x']);
    DB::table('listing_view')->insert(['listing_id' => $listing->listing_id, 'day' => now()->toDateString(), 'viewer_hash' => 'h']);

    asScope('customer', $me, function () {
        expect(DB::table('promo_code_use')->count())->toBe(0)
            ->and(DB::table('listing_view')->count())->toBe(0)
            ->and(DB::table('first_sale_enablement')->count())->toBe(0)
            ->and(DB::table('promo_code')->count())->toBe(0);
    });
});

it('lets a seller read their own advance and nobody else\'s', function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $seller = Orders::seller($order);
    $stranger = Customer::factory()->verified()->create();
    $txn = Ledger::topUp(Customer::factory()->verified()->create(), '100');
    DB::table('first_sale_advance')->insert([
        'order_id' => $order->order_id, 'seller_id' => $seller->customer_id, 'listing_id' => $order->listing_id,
        'amount_egp' => '1000', 'cap_applied_egp' => '100000', 'provisional_proceeds_egp' => '5000',
        'basis' => 'promo_code', 'promo_code' => Promo::code(['kind' => 'first_sale', 'pays_advance' => true, 'first_sale_only' => true])->code,
        'advance_txn_id' => $txn->ledger_txn_id,
    ]);

    asScope('customer', $seller, fn () => expect(DB::table('first_sale_advance')->count())->toBe(1));
    asScope('customer', $stranger, fn () => expect(DB::table('first_sale_advance')->count())->toBe(0));
    asScope('promo', $stranger, fn () => expect(DB::table('first_sale_advance')->count())->toBe(0));
});

it('answers cross-customer counts as plain numbers through functions', function () {
    $me = Customer::factory()->verified()->create();
    $friend = Customer::factory()->verified()->create();
    Promo::referral($friend, null, ['device_fingerprint' => 'dev-hash']);

    asScope('promo', $me, function () use ($friend) {
        expect(DB::table('referral')->count())->toBe(0)
            ->and((int) DB::selectOne('SELECT referral_device_count(?) AS n', ['dev-hash'])->n)->toBe(1)
            ->and((int) DB::selectOne('SELECT referral_inviter_count(?) AS n', [$friend->customer_id])->n)->toBe(1)
            ->and(DB::selectOne('SELECT referral_lookup(?) AS id', ['NOSUCHCD'])->id)->toBeNull();
    });
});
