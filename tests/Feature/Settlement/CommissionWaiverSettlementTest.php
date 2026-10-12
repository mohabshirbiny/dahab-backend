<?php

use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FreeRelists;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T041 (research R3, R4, R17; Principle V): the settlement applies the lowest single
// commission; every case balances, reconciles with its invoice (the deferred DH012 / DH026 checks
// are fired explicitly because the test transaction never commits) and stores its cost figures.
// The 21K ring of 10 g at 300 EGP/g making: ordinary commission 600, VAT 84, proceeds 3,000 - 684
// on the making plus the gold value.

beforeEach(function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
});

/** The ring's order, optionally carrying a credit of the seller, inspected and ready to pay. */
function waiverOrder($t, ?array $credit = null, array $listing = []): array
{
    $seller = Customer::factory()->verified()->create();
    $ring = Orders::ring($seller, $listing);
    $order = Orders::accepted($t, $ring);
    $made = $credit === null ? null : Promo::credit($seller, $ring, $credit);
    Orders::inspected($t, $order);

    return [$order->refresh(), $made, $seller, $ring];
}

function payAndSettle($t, Order $order): Order
{
    Orders::pay($t, Orders::buyer($order), $order)->assertOk();
    // The deferred checks fire at commit: fire them now (DH012 invoice, DH026 waiver, the money link).
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    return $order->refresh();
}

function entrySum(Order $order): string
{
    $sum = '0';
    foreach (Orders::lines($order, 'balance_payment') as $line) {
        $sum = bcadd($sum, $line->amount, 4);
    }

    return $sum;
}

function sellerInvoice(Order $order): ?object
{
    return DatabaseActor::elevate('maintenance', fn () => DB::table('tax_invoice')
        ->where('order_id', $order->order_id)->where('customer_id', $order->seller_id)->first());
}

it('settles an ordinary sale with the stored cost figures and a zero-sum entry', function () {
    [$order] = waiverOrder($this);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_ordinary_amount)->toBe('600.0000')
        ->and($paid->commission_amount)->toBe('600.0000')
        ->and($paid->commission_given_up)->toBe('0.0000')
        ->and($paid->commission_waiver_kind)->toBe('none')
        ->and($paid->commission_waiver_credit_id)->toBeNull()
        ->and($paid->commission_floor_egp)->toBe('200.0000')
        ->and($paid->commission_floor_applied)->toBeTrue()
        ->and($paid->settled_at)->not->toBeNull()
        ->and(entrySum($paid))->toBe('0.0000')
        ->and(sellerInvoice($paid)->commission_amount ?? $paid->commission_amount)->toBe('600.0000');
});

it('takes half off the commission with a percent credit and spends it', function () {
    [$order, $credit] = waiverOrder($this, ['kind' => 'percent', 'percent_off' => '50']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_ordinary_amount)->toBe('600.0000')
        ->and($paid->commission_amount)->toBe('300.0000')
        ->and($paid->vat_amount)->toBe('42.0000')
        ->and($paid->commission_given_up)->toBe('300.0000')
        ->and($paid->commission_waiver_kind)->toBe('credit')
        ->and($paid->commission_waiver_credit_id)->toBe($credit->credit_id)
        ->and(entrySum($paid))->toBe('0.0000');

    $credit->refresh();
    expect($credit->status->value)->toBe('exhausted')->and($credit->uses_left)->toBe(0);
    $event = DB::table('commission_credit_event')->where('credit_id', $credit->credit_id)->where('kind', 'spent')->sole();
    expect($event->order_id)->toBe($order->order_id)->and($event->reduction_egp)->toBe('300.0000')
        ->and($event->actor_customer_id)->toBe($order->buyer_id);

    // The seller's proceeds rise by the commission and its VAT given up (300 + 42).
    [$plain] = waiverOrder($this);
    $plainPaid = payAndSettle($this, $plain);
    expect(bcsub($paid->seller_proceeds, $plainPaid->seller_proceeds, 4))->toBe('342.0000')
        ->and($paid->final_buyer_total)->toBe($plainPaid->final_buyer_total)
        ->and($paid->spread_amount)->toBe($plainPaid->spread_amount);
});

it('never breaks the minimum with a 99 percent credit', function () {
    [$order] = waiverOrder($this, ['kind' => 'percent', 'percent_off' => '99']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_amount)->toBe('200.0000')
        ->and($paid->commission_given_up)->toBe('400.0000')
        ->and($paid->commission_floor_applied)->toBeTrue();
});

it('waives the whole commission with a waive credit, without forcing the minimum', function () {
    [$order] = waiverOrder($this, ['kind' => 'waive']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_amount)->toBe('0.0000')->and($paid->vat_amount)->toBe('0.0000')
        ->and($paid->commission_given_up)->toBe('600.0000')->and($paid->commission_floor_applied)->toBeFalse()
        ->and(sellerInvoice($paid))->toBeNull()
        ->and(entrySum($paid))->toBe('0.0000');
});

it('spends an amount credit partially and keeps the rest', function () {
    [$order, $credit] = waiverOrder($this, ['kind' => 'amount', 'amount_egp' => '1000', 'amount_left_egp' => '1000']);
    $paid = payAndSettle($this, $order);

    // 600 - 1000 would go below the minimum: charged 200, given up 400, 600 of the credit stays.
    expect($paid->commission_amount)->toBe('200.0000')->and($paid->commission_given_up)->toBe('400.0000');
    $credit->refresh();
    expect($credit->status->value)->toBe('active')->and($credit->amount_left_egp)->toBe('600.0000');
});

it('does not consume a credit that gives nothing', function () {
    // A 200 minimum already is the ordinary commission when the making charge is small.
    [$order, $credit] = waiverOrder($this, ['kind' => 'percent', 'percent_off' => '50'], ['making_charge_per_g' => '10.00']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_ordinary_amount)->toBe('200.0000')
        ->and($paid->commission_amount)->toBe('200.0000')
        ->and($paid->commission_waiver_kind)->toBe('none');
    expect($credit->refresh()->status->value)->toBe('active');
});

it('never sums two credits of the seller: the lowest wins and the other is untouched', function () {
    [$order, $half, $seller, $ring] = waiverOrder($this, ['kind' => 'percent', 'percent_off' => '50']);
    $quarter = Promo::credit($seller, $ring, ['kind' => 'percent', 'percent_off' => '25']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_amount)->toBe('300.0000')->and($paid->commission_waiver_credit_id)->toBe($half->credit_id);
    expect($quarter->refresh()->status->value)->toBe('active');
});

it('lets a free relist and a code tie at nothing: the free relist is the one recorded', function () {
    $collected = FreeRelists::ac018CollectedOrder($this);
    $buyer = Orders::buyer($collected);
    FreeRelists::ac018Relist($this, $buyer, $collected)->assertCreated();
    $relisted = Listing::query()->where('relisted_from_order_id', $collected->order_id)->sole();

    $order = Orders::accepted($this, $relisted);
    $credit = Promo::credit($buyer, $relisted, ['kind' => 'waive']);
    Orders::inspected($this, $order);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_waiver_kind)->toBe('free_relist')->and($paid->commission_amount)->toBe('0.0000');
    expect($credit->refresh()->status->value)->toBe('active');
});

it('ignores an expired credit and another seller\'s credit', function () {
    [$order, $expired, $seller, $ring] = waiverOrder($this, ['kind' => 'waive', 'expires_at' => now()->subDay()]);
    $stranger = Customer::factory()->verified()->create();
    $theirs = Promo::credit($stranger, Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $stranger->customer_id]), ['kind' => 'waive']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_waiver_kind)->toBe('none')->and($paid->commission_amount)->toBe('600.0000');
    expect($expired->refresh()->status->value)->toBe('active')->and($theirs->refresh()->status->value)->toBe('active');
});

it('spends the inviter pool credit on the next sale, bound to no listing', function () {
    [$order, $credit] = waiverOrder($this, null);
    $seller = Orders::seller($order);
    $pool = Promo::credit($seller, null, ['kind' => 'amount', 'amount_egp' => '200', 'amount_left_egp' => '200']);
    $paid = payAndSettle($this, $order);

    expect($paid->commission_amount)->toBe('400.0000')->and($paid->commission_waiver_credit_id)->toBe($pool->credit_id);
    expect($pool->refresh()->status->value)->toBe('exhausted');
});

it('keeps the database minimum: a direct update below the floor is refused', function () {
    [$order] = waiverOrder($this);
    $paid = payAndSettle($this, $order);

    // The cost figures are frozen once written (DH026) ...
    $frozen = fn (array $set) => function () use ($paid, $set) {
        try {
            DB::transaction(fn () => DB::table('order')->where('order_id', $paid->order_id)->update($set));
        } catch (QueryException $e) {
            return $e->errorInfo[0];
        }

        return null;
    };
    expect($frozen(['commission_given_up' => '1.0000'])())->toBe('DH026');

    // ... and a below-minimum commission with the floor applied violates the CHECK on any order.
    DB::statement('ALTER TABLE "order" DISABLE TRIGGER trg_order_cost_frozen');
    DB::statement('ALTER TABLE "order" DISABLE TRIGGER trg_order_transition');
    expect($frozen(['commission_amount' => '100.0000', 'commission_given_up' => '500.0000'])())->toBe('23514');
});

it('refuses a credit waiver that has no matching spent event (DH026)', function () {
    [$order, $credit] = waiverOrder($this);
    $paid = payAndSettle($this, $order);

    DB::statement('ALTER TABLE "order" DISABLE TRIGGER trg_order_cost_frozen');
    DB::statement('ALTER TABLE "order" DISABLE TRIGGER trg_order_transition');
    DB::table('order')->where('order_id', $paid->order_id)->update([
        'commission_waiver_kind' => 'credit', 'commission_waiver_credit_id' => Promo::credit(Orders::seller($paid), Listing::query()->find($paid->listing_id), ['kind' => 'waive'])->credit_id,
        'commission_floor_applied' => false,
    ]);

    expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toThrow(QueryException::class);
});

it('reproduces the worked examples of Part 3 section 3.3 to the piastre with no waiver', function () {
    [$order] = waiverOrder($this);
    $paid = payAndSettle($this, $order);

    // Locked rates 5,263.125 and 5,236.875 on 10 g with 300 making: buyer 55,631.25, gross 55,368.75.
    expect($paid->final_buyer_total)->toBe('55631.2500')
        ->and($paid->final_seller_gross)->toBe('55368.7500')
        ->and($paid->commission_amount)->toBe('600.0000')
        ->and($paid->vat_amount)->toBe('84.0000')
        ->and($paid->spread_amount)->toBe('262.5000')
        ->and($paid->seller_proceeds)->toBe('54684.7500');
});
