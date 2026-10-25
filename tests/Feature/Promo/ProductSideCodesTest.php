<?php

use App\Enums\SeedRole;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\BuyRequests\DepositRule;
use App\Support\DatabaseActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// docs/features/promo-code-product-side.md: a code is for one product (gold, diamond, gold with
// diamond) and one side. Sellers get a commission waiver / percentage / amount; a gold buyer pays
// the seller's price; a diamond or mix buyer gets a lower deposit.

function productCode(array $body): TestResponse
{
    return test()->postJson('/api/v1/dashboard/promo-codes', $body, Promo::key());
}

describe('creating the codes', function () {
    beforeEach(function () {
        TopUps::actAsStaff($this, SeedRole::FINANCE);
    });

    it('creates a gold buyer code for anyone that gives the seller\'s price', function () {
        productCode(['code' => 'gold-buyer', 'side' => 'buyer', 'product_type' => 'gold'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'buyer')
            ->assertJsonPath('data.side', 'buyer')
            ->assertJsonPath('data.product_type', 'gold')
            ->assertJsonPath('data.what_it_does.gives_spread', true)
            ->assertJsonPath('data.what_it_does.deposit.mode', 'none')
            ->assertJsonPath('data.tied_to.audience', 'anyone');

        productCode(['code' => 'GOLD-DEP', 'side' => 'buyer', 'product_type' => 'gold', 'deposit_percent' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('deposit_percent');
    });

    it('creates a diamond and a mix buyer code with a deposit percentage or amount', function () {
        productCode(['code' => 'DIA-PCT', 'side' => 'buyer', 'product_type' => 'diamond', 'deposit_percent' => 50])
            ->assertCreated()
            ->assertJsonPath('data.what_it_does.gives_spread', false)
            ->assertJsonPath('data.what_it_does.deposit.mode', 'percent')
            ->assertJsonPath('data.what_it_does.deposit.percent', '50.0000');

        productCode(['code' => 'MIX-AMT', 'side' => 'buyer', 'product_type' => 'gold_with_diamond', 'deposit_amount_egp' => 500])
            ->assertCreated()
            ->assertJsonPath('data.what_it_does.deposit.mode', 'amount')
            ->assertJsonPath('data.what_it_does.deposit.amount_egp', '500.0000');

        productCode(['code' => 'DIA-NONE', 'side' => 'buyer', 'product_type' => 'diamond'])
            ->assertStatus(422)->assertJsonValidationErrors('deposit_percent');
        productCode(['code' => 'DIA-BOTH', 'side' => 'buyer', 'product_type' => 'diamond', 'deposit_percent' => 5, 'deposit_amount_egp' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('deposit_percent');
        productCode(['code' => 'NO-PRODUCT', 'side' => 'buyer', 'deposit_percent' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('product_type');
    });

    it('creates a seller code by product with a waiver, a percentage or an amount, for anyone', function () {
        productCode(['code' => 'SELL-AMT', 'side' => 'seller', 'product_type' => 'diamond', 'commission_mode' => 'amount', 'commission_amount_egp' => 150])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'discount')
            ->assertJsonPath('data.side', 'seller')
            ->assertJsonPath('data.product_type', 'diamond')
            ->assertJsonPath('data.what_it_does.commission.mode', 'amount')
            ->assertJsonPath('data.what_it_does.commission.amount_egp', '150.0000')
            ->assertJsonPath('data.tied_to.audience', 'anyone');

        productCode(['code' => 'SELL-NOAMT', 'side' => 'seller', 'product_type' => 'gold', 'commission_mode' => 'amount'])
            ->assertStatus(422)->assertJsonValidationErrors('commission_amount_egp');
        productCode(['code' => 'SELL-NOPRODUCT', 'side' => 'seller', 'commission_mode' => 'waive'])
            ->assertStatus(422)->assertJsonValidationErrors('product_type');
        productCode(['code' => 'SELL-DEP', 'side' => 'seller', 'product_type' => 'gold', 'commission_mode' => 'waive', 'deposit_percent' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('deposit_percent');
        productCode(['code' => 'BUYER-COMM', 'side' => 'buyer', 'product_type' => 'gold', 'commission_mode' => 'waive'])
            ->assertStatus(422)->assertJsonValidationErrors('commission_mode');
    });

    it('keeps the terms immutable in the database', function () {
        productCode(['code' => 'LOCKED', 'side' => 'buyer', 'product_type' => 'diamond', 'deposit_percent' => 20])->assertCreated();

        expect(fn () => DatabaseActor::elevate('maintenance', fn () => DB::table('promo_code')->where('code', 'LOCKED')->update(['deposit_percent' => 90])))
            ->toThrow(QueryException::class);
    });
});

describe('the seller side', function () {
    beforeEach(function () {
        Orders::workedPrices();
        $this->seller = Customer::factory()->verified()->create();
        Promo::open();
    });

    it('takes an amount off the commission on a piece of the code\'s product', function () {
        Promo::code(['code' => 'OFF150', 'product_type' => 'gold', 'commission_mode' => 'amount', 'commission_amount_egp' => '150']);

        $body = ['category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'making_charge_per_g' => '300.00', 'code' => 'off150'];
        $data = Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, $body)->assertOk()->json('data');

        expect($data['code']['commission'])->toBe(['mode' => 'amount', 'percent' => null, 'amount_egp' => '150.0000'])
            ->and($data['commission']['ordinary_total_egp'])->toBe('600.0000')
            ->and($data['commission']['charged_total_egp'])->toBe('450.0000')
            ->and($data['commission']['saved_total_egp'])->toBe('150.0000');
    });

    it('refuses a code on a piece of another product', function () {
        Promo::code(['code' => 'DIAONLY', 'product_type' => 'diamond', 'commission_mode' => 'waive']);

        $body = ['category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'making_charge_per_g' => '300.00', 'code' => 'DIAONLY'];
        Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, $body)
            ->assertStatus(422)->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', 'wrong_product');
    });

    it('does not let a seller use a buyer code', function () {
        Promo::code(['code' => 'BUYER-ONLY', 'kind' => 'buyer', 'side' => 'buyer', 'product_type' => 'gold', 'commission_mode' => 'none',
            'commission_waived' => false, 'gives_spread' => true]);

        $body = ['category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'making_charge_per_g' => '300.00', 'code' => 'BUYER-ONLY'];
        Listings::as($this, $this->seller)->postJson(Promo::PREVIEW_URL, $body)
            ->assertStatus(422)->assertJsonPath('details.reason', 'unknown');
    });
});

describe('the buyer side', function () {
    beforeEach(function () {
        Storage::fake('identity_private');
        Notification::fake();
        Listings::goldPrice();
        $this->seed(DashboardRolesAndPermissionsSeeder::class);

        $this->buyer = BuyRequests::funded('500000');
        $this->seller = Customer::factory()->verified()->create();
        $this->gold = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
        $this->diamond = Listing::factory()->diamond()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
    });

    function buyerCode(array $attributes): PromoCode
    {
        return Promo::code($attributes + ['kind' => 'buyer', 'side' => 'buyer', 'commission_mode' => 'none', 'commission_waived' => false]);
    }

    it('lets any buyer use a gold code: the spread is handed back and the deposit is the same', function () {
        buyerCode(['code' => 'GOLD-BUY', 'product_type' => 'gold', 'gives_spread' => true]);

        $res = BuyRequests::send($this, $this->buyer, $this->gold, ['promo_code' => 'gold-buy'])->assertCreated();
        $request = BuyRequest::query()->findOrFail($res->json('data.id'));

        expect($request->promo_code)->toBe('GOLD-BUY')
            ->and($request->promo_gives_spread)->toBeTrue()
            ->and($request->promo_waives_commission)->toBeFalse()
            ->and($request->deposit_discount_egp)->toBe('0.0000')
            ->and($request->deposit_amount)->toBe(app(DepositRule::class)->deposit($request->locked_total_price));
    });

    it('takes a percentage off the deposit of a diamond', function () {
        buyerCode(['code' => 'DIA-HALF', 'product_type' => 'diamond', 'deposit_mode' => 'percent', 'deposit_percent' => '50']);

        $res = BuyRequests::send($this, $this->buyer, $this->diamond, ['promo_code' => 'DIA-HALF'])->assertCreated();
        $request = BuyRequest::query()->findOrFail($res->json('data.id'));
        $full = app(DepositRule::class)->deposit($request->locked_total_price);

        expect($request->deposit_amount)->toBe(app(DepositRule::class)->discounted($full, PromoCode::query()->find('DIA-HALF')))
            ->and(bccomp($request->deposit_amount, $full, 4))->toBe(-1)
            ->and(bcadd($request->deposit_amount, $request->deposit_discount_egp, 4))->toBe($full)
            ->and($request->promo_gives_spread)->toBeFalse();
    });

    it('takes an amount off the deposit but never below the minimum', function () {
        buyerCode(['code' => 'DIA-BIG', 'product_type' => 'diamond', 'deposit_mode' => 'amount', 'deposit_amount_egp' => '99999999']);

        $res = BuyRequests::send($this, $this->buyer, $this->diamond, ['promo_code' => 'DIA-BIG'])->assertCreated();

        expect(BuyRequest::query()->findOrFail($res->json('data.id'))->deposit_amount)->toBe('1.0000');
    });

    it('refuses a code for another product and logs it', function () {
        buyerCode(['code' => 'GOLD-ONLY', 'product_type' => 'gold', 'gives_spread' => true]);

        BuyRequests::send($this, $this->buyer, $this->diamond, ['promo_code' => 'GOLD-ONLY'])
            ->assertStatus(422)->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', 'wrong_product');
        expect(DB::table('promo_code_use')->where('code', 'GOLD-ONLY')->where('allowed', false)->count())->toBe(1);
    });

    it('keeps a code tied to an account to that account and honours a once-per-person limit', function () {
        buyerCode(['code' => 'ONLY-ME', 'product_type' => 'gold', 'gives_spread' => true, 'audience' => 'account', 'tied_customer_id' => $this->buyer->customer_id]);
        $other = BuyRequests::funded('500000');
        BuyRequests::send($this, $other, $this->gold, ['promo_code' => 'ONLY-ME'])
            ->assertStatus(422)->assertJsonPath('details.reason', 'not_your_code');

        buyerCode(['code' => 'ONCE', 'product_type' => 'gold', 'gives_spread' => true, 'use_limit' => 'once']);
        BuyRequests::send($this, $this->buyer, $this->gold, ['promo_code' => 'ONCE'])->assertCreated();
        $second = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
        BuyRequests::send($this, $this->buyer, $second, ['promo_code' => 'ONCE'])
            ->assertStatus(422)->assertJsonPath('details.reason', 'already_used');
    });

    it('does not let a seller\'s code or a dealer\'s approval rules touch a buyer code', function () {
        // No MarketMakerApproval and a piece listed today: a buyer code needs neither.
        buyerCode(['code' => 'ANY-PIECE', 'product_type' => 'gold', 'gives_spread' => true]);
        $fresh = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id, 'listed_at' => now()]);

        BuyRequests::send($this, $this->buyer, $fresh, ['promo_code' => 'ANY-PIECE'])->assertCreated();
    });
});

describe('settlement', function () {
    beforeEach(function () {
        Storage::fake('identity_private');
        Orders::workedPrices();
    });

    function paidWith($test, ?array $flags): Order
    {
        $order = Orders::accepted($test);

        if ($flags !== null) {
            $staff = Staff::factory()->create();
            $code = 'BUY-'.strtoupper(substr($order->order_id, 0, 6));
            DatabaseActor::elevate('maintenance', function () use ($order, $staff, $code, $flags) {
                PromoCode::query()->create([
                    'code' => $code, 'kind' => 'buyer', 'side' => 'buyer', 'product_type' => 'gold', 'audience' => 'anyone',
                    'gives_spread' => true, 'created_by' => $staff->staff_id,
                ]);
                DB::table('buy_request')->where('buy_request_id', $order->buy_request_id)->update(['promo_code' => $code] + $flags);
            });
        }

        Orders::inspected($test, $order);
        Orders::pay($test, Orders::buyer($order), $order)->assertOk();

        return $order->refresh();
    }

    it('hands a gold buyer code\'s spread back to the buyer and still charges the seller the commission', function () {
        $ordinary = paidWith($this, null);
        $order = paidWith($this, ['promo_gives_spread' => true, 'promo_waives_commission' => false]);

        $kinds = [];
        foreach (Orders::lines($order, 'balance_payment') as $line) {
            $kinds[$line->kind] = bcadd($kinds[$line->kind] ?? '0', $line->amount, 4);
        }

        expect($kinds)->not->toHaveKey('dahab_spread')->and($kinds)->toHaveKey('dahab_commission')
            ->and(bccomp($order->commission_amount, '0', 4))->toBe(1)
            ->and(bccomp($order->spread_amount, '0', 4))->toBe(1)
            ->and($order->final_buyer_total)->toBe($ordinary->final_buyer_total);

        $total = '0';
        foreach (Orders::lines($order, 'balance_payment') as $line) {
            $total = bcadd($total, $line->amount, 4);
        }
        expect($total)->toBe('0.0000');
    });
});
