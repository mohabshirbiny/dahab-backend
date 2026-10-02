<?php

use App\Enums\AccountKind;
use App\Models\GoldPrice;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-014–FR-017, research R6–R7; Part 3 §3.3–§3.4 end to end
// through HTTP, to the piastre: one balanced balance_payment through escrow.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('settles Part 3 §3.3 to the piastre', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $buyer = Orders::buyer($order);
    $seller = Orders::seller($order);
    $before = BuyRequests::balances($seller);

    Orders::pay($this, $buyer, $order)->assertOk()->assertJsonPath('data.state', 'ready_to_collect');

    expect(BuyRequests::balances($buyer))->toBe(['available' => '4368.7500', 'held' => '0.0000'])
        ->and(bcsub(BuyRequests::balances($seller)['available'], $before['available'], 4))->toBe('54684.7500')
        ->and(Orders::internal(AccountKind::DAHAB_COMMISSION))->toBe('600.0000')
        ->and(Orders::internal(AccountKind::VAT_PAYABLE))->toBe('84.0000')
        ->and(Orders::internal(AccountKind::DAHAB_SPREAD))->toBe('262.5000')
        ->and(Orders::internal(AccountKind::ESCROW))->toBe('0.0000');

    $lines = collect(Orders::lines($order->refresh(), 'balance_payment'))->map(fn ($l) => [$l->kind, $l->amount])->all();
    expect($lines)->toBe([
        ['cust_available', '-44505.0000'],
        ['cust_held', '-11126.2500'],
        ['escrow', '55631.2500'],
        ['escrow', '-55631.2500'],
        ['cust_available', '54684.7500'],
        ['dahab_commission', '600.0000'],
        ['vat_payable', '84.0000'],
        ['dahab_spread', '262.5000'],
    ]);

    expect([(string) $order->final_buyer_total, (string) $order->final_seller_gross, (string) $order->commission_amount,
        (string) $order->vat_amount, (string) $order->spread_amount, (string) $order->seller_proceeds, (string) $order->balance_amount])
        ->toBe(['55631.2500', '55368.7500', '600.0000', '84.0000', '262.5000', '54684.7500', '44505.0000'])
        ->and(DatabaseActor::elevate('maintenance', fn () => (string) DB::selectOne('SELECT must_be_zero FROM ledger_global_zero')->must_be_zero))
        ->toBe('0.0000');
});

it('settles Part 3 §3.4 on the IGI weight of 9.900 g', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '9.900');

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    $order->refresh();
    expect((string) $order->final_buyer_total)->toBe('55074.9375')
        ->and((string) $order->balance_amount)->toBe('43948.6875')
        ->and((string) $order->seller_proceeds)->toBe('54137.9025')
        ->and((string) $order->final_weight_g)->toBe('9.900');
});

it('settles on the locked rates whatever the gold price does after acceptance', function () {
    $order = Orders::accepted($this);
    GoldPrice::query()->create(['source' => 'feed', 'bid_24k' => '7000', 'ask_24k' => '7100', 'recorded_by' => SystemActor::id()]);
    Orders::inspected($this, $order, '10.000');

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    expect((string) $order->refresh()->seller_proceeds)->toBe('54684.7500');
});

it('reads commission and VAT live at payment', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'commission.gold_pct')->update(['value_numeric' => 10]));

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    // 10% of 3,000 = 300; VAT 42; proceeds 55,368.75 − 342.
    expect((string) $order->refresh()->commission_amount)->toBe('300.0000')
        ->and((string) $order->vat_amount)->toBe('42.0000')
        ->and((string) $order->seller_proceeds)->toBe('55026.7500');
});
