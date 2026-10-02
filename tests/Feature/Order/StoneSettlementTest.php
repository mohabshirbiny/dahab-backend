<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 research R6–R7; Part 3 §2.4, §3.6: stones settle on the asking
// price with commission on the value above gold and no spread; a deposit
// larger than the total comes back in the same transaction.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
});

function inspectedPiece($test, Listing $listing, array $result, string $funds = '200000')
{
    $order = Orders::accepted($test, $listing, $funds);
    Orders::staff($test, SeedRole::OPERATIONS);
    Orders::receive($test, $order)->assertOk();
    Orders::staff($test, SeedRole::IGI_BRANCH);
    Orders::result($test, $order, $result)->assertCreated();

    return $order->refresh();
}

it('settles a diamond with no spread line and stone commission', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $order = inspectedPiece($this, $diamond, ['measured_stone_grade' => 'VS1 G']);

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    $order->refresh();
    expect((string) $order->final_buyer_total)->toBe('120000.0000')
        ->and((string) $order->commission_amount)->toBe('6000.0000')
        ->and((string) $order->spread_amount)->toBe('0.0000')
        ->and(collect(Orders::lines($order, 'balance_payment'))->pluck('kind')->all())->not->toContain('dahab_spread');
});

it('protects the gold of a gold-with-diamond piece at the locked mid on the measured weight', function () {
    $piece = Listing::factory()->goldWithDiamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $order = inspectedPiece($this, $piece, ['measured_karat' => 21, 'measured_weight_g' => '6.100']);

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    // mid(21K) = 5,250 at bid = ask = 5,994; gold 6.1 g = 32,025; 5% of 80,150 − 32,025 = 2,406.25.
    expect((string) $order->refresh()->commission_amount)->toBe('2406.2500')
        ->and((string) $order->spread_amount)->toBe('0.0000');
});

it('settles a regrade on the accepted proposed price', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $this->seller->customer_id]);
    $order = inspectedPiece($this, $diamond, ['measured_stone_grade' => 'VS2 G', 'stone_below_claim' => true]);
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->postJson(Orders::STAFF_URL."/{$order->order_id}/propose-price", ['price' => '108000', 'reason' => 'Grade VS2 per IGI result.'],
        ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    Listings::as($this, Orders::buyer($order))->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/decision",
        ['accept' => true, 'inspection_id' => $order->latestInspection()->inspection_id], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    Orders::pay($this, Orders::buyer($order), $order->refresh())->assertOk();

    expect((string) $order->refresh()->final_buyer_total)->toBe('108000.0000')
        ->and((string) $order->balance_amount)->toBe('84000.0000');
});

it('gives back the excess when the total is below the deposit', function () {
    // A 1 g ring: deposit 20% of the stated 10 g price; IGI finds 1 g (a big drop, accepted as a correction to pass by tolerance).
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'inspection.weight_tolerance_pct')->update(['value_numeric' => 95]));
    $order = Orders::inspected($this, Orders::accepted($this), '1.000');
    $buyer = Orders::buyer($order);

    Orders::pay($this, $buyer, $order)->assertOk();

    // Total on 1 g = 5,263.125 + 300 = 5,563.125; deposit 11,126.25 → 5,563.125 back.
    $order->refresh();
    expect((string) $order->balance_amount)->toBe('0.0000')
        ->and(BuyRequests::balances($buyer))->toBe(['available' => '54436.8750', 'held' => '0.0000'])
        ->and(Orders::internal(AccountKind::ESCROW))->toBe('0.0000');
});
