<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Enums\SettingKey;
use App\Models\Customer;
use App\Models\FirstSaleAdvance;
use App\Models\InspectionResult;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\Listings\ListingPriceLocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T052 (research R12, R14; TS Part 3 §6.4–6.5): payment after an advance reconciles it,
// a final amount below it is Dahab's cost, an order that ends unpaid writes it off. Every case
// balances and its invoice reconciles (the deferred checks are fired explicitly).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Promo::open(firstSale: true);
});

function advancedOrder($t, string $weight = '10.000'): Order
{
    $seller = Customer::factory()->verified()->create();
    $ring = Orders::ring($seller);
    $code = Promo::code(['code' => 'EARLY-'.strtoupper(substr(md5((string) random_int(0, 999999)), 0, 6)), 'kind' => 'first_sale',
        'pays_advance' => true, 'first_sale_only' => true, 'commission_mode' => 'none']);
    DB::table('listing_promo')->insert(['listing_id' => $ring->listing_id, 'promo_code' => $code->code]);

    return Orders::inspected($t, Orders::accepted($t, $ring), $weight);
}

function theAdvance(Order $order): FirstSaleAdvance
{
    return DatabaseActor::elevate('maintenance', fn () => FirstSaleAdvance::query()->where('order_id', $order->order_id)->firstOrFail());
}

function balancesAndFire(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function entryTotal(Order $order, string $kind): string
{
    return array_reduce(Orders::lines($order, $kind), fn ($c, $l) => bcadd($c, $l->amount, 4), '0');
}

it('recovers the advance from the proceeds at the buyer\'s payment', function () {
    $order = advancedOrder($this);
    $seller = Orders::seller($order);
    expect(BuyRequests::balances($seller)['available'])->toBe('54684.7500');

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    balancesAndFire();
    $order->refresh();
    $advance = theAdvance($order)->refresh();

    // The whole entitlement reached the seller exactly once; Dahab's front is back.
    expect($advance->status->value)->toBe('recovered')
        ->and($advance->recovered_egp)->toBe('54684.7500')->and($advance->shortfall_absorbed_egp)->toBe('0.0000')
        ->and($advance->recovered_txn_id)->not->toBeNull()
        ->and($order->seller_proceeds)->toBe('54684.7500')
        ->and(BuyRequests::balances($seller)['available'])->toBe('54684.7500')
        ->and(Orders::internal(AccountKind::EXTERNAL_EQUITY))->toBe('0.0000')
        ->and(entryTotal($order, 'balance_payment'))->toBe('0.0000');

    // The seller has no leg in the settlement entry (zero lines are omitted); equity has one.
    $kinds = collect(Orders::lines($order, 'balance_payment'))->pluck('kind')->all();
    expect($kinds)->toContain('external_equity')->toContain('dahab_commission')->toContain('vat_payable');
    expect(collect(Orders::lines($order, 'balance_payment'))->where('kind', 'cust_available')->where('customer_id', $seller->customer_id)->count())->toBe(0);
});

it('absorbs a shortfall when the final amount is below the advance, never clawing it back', function () {
    $order = advancedOrder($this);
    $seller = Orders::seller($order);
    $paidAhead = theAdvance($order)->amount_egp;

    // The commission rate rose between the advance and the payment: the proceeds are lower.
    Promo::set(SettingKey::COMMISSION_GOLD_PCT, 50);
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    balancesAndFire();
    $order->refresh();
    $advance = theAdvance($order)->refresh();

    expect(bccomp($order->seller_proceeds, $paidAhead, 4))->toBe(-1)
        ->and($advance->recovered_egp)->toBe($order->seller_proceeds)
        ->and(bcadd($advance->recovered_egp, $advance->shortfall_absorbed_egp, 4))->toBe($paidAhead)
        // The seller keeps the advance whole and gets nothing more: never clawed back.
        ->and(BuyRequests::balances($seller)['available'])->toBe($paidAhead)
        ->and(Orders::internal(AccountKind::EXTERNAL_EQUITY))->toBe('-'.$advance->shortfall_absorbed_egp)
        ->and(entryTotal($order, 'balance_payment'))->toBe('0.0000');
});

it('writes the advance off when the buyer never pays, the seller keeping the advance and the deposit share', function () {
    $order = advancedOrder($this);
    $seller = Orders::seller($order);
    $advance = theAdvance($order);

    $this->travelTo($order->refresh()->balance_due_deadline->addMinute());
    Orders::sweep();
    balancesAndFire();

    $order->refresh();
    $advance->refresh();
    expect($order->state->value)->toBe('cancelled_buyer_nopay')
        ->and($advance->status->value)->toBe('written_off')
        ->and($advance->written_off_reason->value)->toBe('buyer_did_not_pay')
        ->and($advance->written_off_amount_egp)->toBe($advance->amount_egp)
        // No second posting: the cost already left equity when the advance was paid.
        ->and(DB::table('ledger_transaction')->where('order_id', $order->order_id)->where('event_kind', 'first_sale_payout')->count())->toBe(1)
        ->and(Orders::internal(AccountKind::EXTERNAL_EQUITY))->toBe('-'.$advance->amount_egp);

    // The seller keeps the advance and, by default, half of the forfeited deposit.
    // Spec 022 coupling (D23-4): the piece that carried an advance keeps its price, also after a write-off.
    expect(ListingPriceLocks::lockedIds([$order->listing_id]))->toHaveKey($order->listing_id);

    // 20 % of 55,631.25 = 11,126.25; half of it, half-up to the piastre, is 5,563.13.
    expect(bcsub(BuyRequests::balances($seller)['available'], $advance->amount_egp, 4))->toBe('5563.1300');
});

it('sends the seller\'s share of the forfeit to Dahab when Finance offsets the write-off', function () {
    Promo::set(SettingKey::PAYOUT_FIRST_SALE_COMP_OFFSETS_WRITEOFF, true);
    $order = advancedOrder($this);
    $seller = Orders::seller($order);
    $advance = theAdvance($order);

    $this->travelTo($order->refresh()->balance_due_deadline->addMinute());
    Orders::sweep();

    expect(BuyRequests::balances($seller)['available'])->toBe($advance->amount_egp);
});

it('writes the advance off when the buyer declines an adjusted price after it', function () {
    // The advance is only paid once the price is settled; a corrected result that turns a pass
    // into a cancellation after the advance writes it off (the hook is in moveOrder).
    $order = advancedOrder($this);
    $result = InspectionResult::query()->where('order_id', $order->order_id)->sole();

    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $order, ['measured_karat' => 18, 'measured_weight_g' => '10.000', 'supersedes_id' => $result->inspection_id])->assertCreated();
    balancesAndFire();

    expect($order->refresh()->state->value)->toBe('cancelled_inspection')
        ->and(theAdvance($order)->refresh()->status->value)->toBe('written_off')
        ->and(theAdvance($order)->written_off_reason->value)->toBe('cancelled_at_inspection');
});

it('shows staff the exposure, the written-off total and the advances', function () {
    $order = advancedOrder($this);
    Orders::staff($this, SeedRole::FINANCE);

    $res = $this->getJson('/api/v1/dashboard/first-sale-advances')->assertOk();
    expect($res->json('meta'))->toMatchArray([
        'enabled' => true, 'cap_egp' => '100000.0000', 'ceiling_egp' => '1000000.0000',
        'outstanding_egp' => '54684.7500', 'outstanding_count' => 1, 'headroom_egp' => '945315.2500',
        'written_off_total_egp' => '0.0000', 'shortfall_total_egp' => '0.0000',
    ])
        ->and($res->json('data.0.status'))->toBe('outstanding')
        ->and($res->json('data.0.order_ref'))->toBe($order->order_ref)
        ->and($res->json('data.0.basis'))->toBe('promo_code');

    $this->getJson('/api/v1/dashboard/first-sale-advances?status=recovered')->assertOk()->assertJsonPath('data', []);

    // The Operations role holds no first_sale.manage.
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->getJson('/api/v1/dashboard/first-sale-advances')->assertForbidden();
});

it('keeps a settlement without an advance exactly as before', function () {
    $seller = Customer::factory()->verified()->create();
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring($seller)));
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    expect(DB::table('first_sale_advance')->count())->toBe(0)
        ->and(collect(Orders::lines($order, 'balance_payment'))->pluck('kind')->contains('external_equity'))->toBeFalse();
});
