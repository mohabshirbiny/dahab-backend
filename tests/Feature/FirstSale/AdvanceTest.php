<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FirstSaleAdvance;
use App\Models\InspectionResult;
use App\Models\Listing;
use App\Models\Order;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\FreeRelists;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T051 (research R11–R13; TS Part 3 §6.2–6.3): a first gold seller is paid ahead at the
// inspection that passes, behind a switch that is off; one balanced first_sale_payout entry.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

/** A seller's ring with a first-sale code recorded on it, accepted by a buyer; not yet inspected. */
function advanceOrder($t, ?Customer $seller = null, array $listing = []): Order
{
    $seller ??= Customer::factory()->verified()->create();
    $ring = Orders::ring($seller, $listing);
    $code = Promo::code(['code' => 'EARLY-'.strtoupper(substr(md5((string) microtime(true).random_int(0, 99999)), 0, 6)), 'kind' => 'first_sale', 'pays_advance' => true,
        'first_sale_only' => true, 'commission_mode' => 'none']);
    DB::table('listing_promo')->insert(['listing_id' => $ring->listing_id, 'promo_code' => $code->code]);

    return Orders::accepted($t, $ring);
}

function inspectIt($t, Order $order, string $weight = '10.000'): Order
{
    return Orders::inspected($t, $order, $weight);
}

function advanceOf(Order $order): ?FirstSaleAdvance
{
    return DatabaseActor::elevate('maintenance', fn () => FirstSaleAdvance::query()->where('order_id', $order->order_id)->first());
}

function equity(): string
{
    return Orders::internal(AccountKind::EXTERNAL_EQUITY);
}

it('posts nothing on a fresh database: the switch is off', function () {
    $order = inspectIt($this, advanceOrder($this));

    expect(advanceOf($order))->toBeNull()
        ->and(DB::table('ledger_transaction')->where('event_kind', 'first_sale_payout')->count())->toBe(0)
        ->and(BuyRequests::balances(Orders::seller($order))['available'])->toBe('0.0000');
    // The seller is told once why, and staff can see it was skipped.
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'first_sale.skipped')->sole()->after_json['reason']))->toBe('switch_off');
});

it('pays the first gold sale ahead with one balanced entry, once the piece passes', function () {
    Promo::open(firstSale: true);
    $order = advanceOrder($this);
    $seller = Orders::seller($order);

    // Nothing before the inspection.
    expect(advanceOf($order))->toBeNull()->and(BuyRequests::balances($seller)['available'])->toBe('0.0000');

    $order = inspectIt($this, $order);
    $advance = advanceOf($order);

    // Locked rates 5,236.875 x 10 g + 3,000 making = 55,368.75 gross; commission 600 + VAT 84.
    expect($advance)->not->toBeNull()
        ->and($advance->amount_egp)->toBe('54768.7500')
        ->and($advance->status->value)->toBe('outstanding')
        ->and($advance->basis->value)->toBe('promo_code')
        ->and($advance->cap_applied_egp)->toBe('100000.0000')
        ->and($advance->provisional_proceeds_egp)->toBe('54768.7500')
        ->and(BuyRequests::balances($seller)['available'])->toBe('54768.7500')
        ->and(equity())->toBe('-54768.7500');

    $lines = Orders::lines($order, 'first_sale_payout');
    expect($lines)->toHaveCount(2)
        ->and(array_reduce($lines, fn ($c, $l) => bcadd($c, $l->amount, 4), '0'))->toBe('0.0000');

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'first_sale.advanced')->sole());
    expect($audit->actor_staff_id)->toBe(SystemActor::id());

    // The order says so to the seller, and the price of the piece is now fixed (spec 022).
    Orders::show($this, $seller, $order)->assertOk()
        ->assertJsonPath('data.advance.amount_egp', '54768.7500')->assertJsonPath('data.advance.status', 'outstanding');
    expect((bool) DB::selectOne('SELECT listing_price_locked(?) AS l', [$order->listing_id])->l)->toBeTrue();
});

it('shows the advance on Dahab\'s own money: paid out of external_equity and still out', function () {
    Promo::open(firstSale: true);
    $order = inspectIt($this, advanceOrder($this));
    $staff = Orders::staff($this, SeedRole::FINANCE);
    $today = now('Africa/Cairo')->toDateString();

    app('auth')->forgetGuards();
    $r = $this->withToken(staffAccessToken($staff))
        ->getJson('/api/v1/dashboard/wallet-statement?'.http_build_query(['view' => 'equity', 'from' => $today, 'to' => $today]))
        ->assertOk();
    $row = collect($r->json('data.rows'))->firstWhere('group', 'advance_paid');

    expect($r->json('data.summary'))->toMatchArray([
        'out' => '54768.7500', 'closing' => '-54768.7500',
        'advances_outstanding' => '54768.7500', 'advances_outstanding_count' => 1,
    ])
        ->and($row)->toMatchArray(['out' => '54768.7500', 'reference' => $order->order_ref])
        ->and($row['wallet']['customer_id'])->toBe($order->seller_id);
});

it('pays the first sale ahead automatically, with no code and no enablement', function () {
    Promo::open(firstSale: true);
    $order = inspectIt($this, Orders::accepted($this));
    $advance = advanceOf($order);

    expect($advance)->not->toBeNull()
        ->and($advance->basis->value)->toBe('automatic')
        ->and($advance->promo_code)->toBeNull()
        ->and($advance->enablement_id)->toBeNull()
        ->and(BuyRequests::balances(Orders::seller($order))['available'])->toBe($advance->amount_egp)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'first_sale.advanced')->sole()->actor_staff_id))->toBe(SystemActor::id());
});

it('stays silent with the switch off when nothing opened the advance', function () {
    $order = inspectIt($this, Orders::accepted($this));

    expect(advanceOf($order))->toBeNull()
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'like', 'first_sale.%')->count()))->toBe(0);
});

it('never advances a seller whose earlier sale was a diamond piece', function () {
    $seller = Customer::factory()->verified()->create();
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $seller->customer_id]);
    $sold = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $sold)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $sold, ['measured_stone_grade' => 'VS1 G'])->assertCreated();
    Orders::pay($this, Orders::buyer($sold), $sold)->assertOk();
    expect($sold->refresh()->settlement_txn_id)->not->toBeNull();

    // Even a first-sale code on the gold piece does not make it the first sale.
    Promo::open(firstSale: true);
    $order = inspectIt($this, advanceOrder($this, $seller));

    expect(advanceOf($order))->toBeNull()
        ->and(DB::table('ledger_transaction')->where('event_kind', 'first_sale_payout')->count())->toBe(0);
});

it('caps the advance at the first-sale cap', function () {
    Promo::open(firstSale: true);
    Promo::set(SettingKey::PAYOUT_FIRST_SALE_CAP_EGP, 10000);
    $order = inspectIt($this, advanceOrder($this));

    expect(advanceOf($order)->amount_egp)->toBe('10000.0000')
        ->and(advanceOf($order)->cap_applied_egp)->toBe('10000.0000');
});

it('skips when the exposure ceiling would be passed, and says so', function () {
    Promo::open(firstSale: true);
    Promo::set(SettingKey::PAYOUT_FIRST_SALE_EXPOSURE_CEILING_EGP, 10000);
    $order = inspectIt($this, advanceOrder($this));

    expect(advanceOf($order))->toBeNull()
        ->and(BuyRequests::balances(Orders::seller($order))['available'])->toBe('0.0000')
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'first_sale.skipped')->sole()->after_json['reason']))->toBe('ceiling');
});

it('never advances a diamond or a gold-with-diamond piece', function () {
    Promo::open(firstSale: true);
    $seller = Customer::factory()->verified()->create();
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $seller->customer_id]);
    $code = Promo::code(['code' => 'EARLY-D', 'kind' => 'first_sale', 'pays_advance' => true, 'first_sale_only' => true, 'commission_mode' => 'none']);
    DB::table('listing_promo')->insert(['listing_id' => $diamond->listing_id, 'promo_code' => $code->code]);

    $order = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $order, ['measured_stone_grade' => 'VS1 G'])->assertCreated();

    expect(advanceOf($order))->toBeNull()
        ->and(DB::table('ledger_transaction')->where('event_kind', 'first_sale_payout')->count())->toBe(0);
});

it('advances only once per seller, however many orders they have', function () {
    Promo::open(firstSale: true);
    $seller = Customer::factory()->verified()->create();
    $first = inspectIt($this, advanceOrder($this, $seller));
    $second = inspectIt($this, advanceOrder($this, $seller));

    expect(advanceOf($first))->not->toBeNull()->and(advanceOf($second))->toBeNull()
        ->and(DB::table('first_sale_advance')->where('seller_id', $seller->customer_id)->count())->toBe(1);
});

it('never advances a suspended seller', function () {
    Promo::open(firstSale: true);
    $seller = Customer::factory()->verified()->create();
    $order = advanceOrder($this, $seller);
    FreeRelists::ac018Suspend($seller);

    $order = inspectIt($this, $order);

    expect(advanceOf($order))->toBeNull();
});

it('waits for the buyer to accept an adjusted price, then advances on that price', function () {
    Promo::open(firstSale: true);
    $order = inspectIt($this, advanceOrder($this), '9.700');   // 3 % lighter: the buyer decides

    expect($order->state->value)->toBe('weight_adjust_pending')->and(advanceOf($order))->toBeNull();

    $result = InspectionResult::query()->where('order_id', $order->order_id)->sole();
    Listings::as($this, Orders::buyer($order))->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/decision",
        ['accept' => true, 'inspection_id' => $result->inspection_id], Listings::key())->assertOk();

    $advance = advanceOf($order);
    expect($advance)->not->toBeNull()
        // 9.7 g: gold 5,236.875 x 9.7 = 50,797.6875 + making 2,910 = 53,707.6875 - 582 - 81.48 (commission + VAT)
        ->and($advance->amount_egp)->toBe('53125.6875');
});

it('opens the advance for one seller with a staff enablement, used by the next gold sale', function () {
    Promo::open(firstSale: true);
    $seller = Customer::factory()->verified()->create();
    $order = Orders::accepted($this, Orders::ring($seller));

    $staff = Orders::staff($this, SeedRole::FINANCE);
    $this->postJson("/api/v1/dashboard/customers/{$seller->customer_id}/first-sale-advance", ['reason' => 'Walked in with the piece'], Promo::key())->assertCreated();

    $order = inspectIt($this, $order);
    $advance = advanceOf($order);

    expect($advance->basis->value)->toBe('staff_enablement')
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'first_sale.advanced')->sole()->actor_staff_id))->toBe($staff->staff_id)
        ->and(DB::table('first_sale_enablement')->where('seller_id', $seller->customer_id)->value('used_advance_id'))->toBe($advance->advance_id);
});

it('lets the seller withdraw the advance through the ordinary wallet rules', function () {
    Promo::open(firstSale: true);
    $order = inspectIt($this, advanceOrder($this));
    $seller = Orders::seller($order);

    // The advance sits in the available balance like any other money.
    expect(BuyRequests::balances($seller)['available'])->toBe('54768.7500')->and(BuyRequests::balances($seller)['held'])->toBe('0.0000');
});
