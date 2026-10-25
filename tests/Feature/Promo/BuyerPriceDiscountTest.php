<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PromoCodeUse;
use App\Models\TaxInvoice;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// docs/features/buyer-price-discount.md: a buyer code takes a percentage or an amount off the price,
// paid by Dahab's commission or (gold) the spread, never above what pays for it. The piece page
// previews it without recording a use.

const BPD_CODES = '/api/v1/dashboard/promo-codes';
const BPD_PREVIEW = '/api/v1/customer/me/buy-requests/promo-preview';

describe('making the codes', function () {
    beforeEach(fn () => TopUps::actAsStaff($this, SeedRole::FINANCE));

    it('makes a price discount paid by the commission or the spread', function () {
        $this->postJson(BPD_CODES, ['code' => 'GOLD10', 'side' => 'buyer', 'product_type' => 'gold', 'price_percent' => 10, 'discount_funded_by' => 'commission'], Promo::key())
            ->assertCreated()
            ->assertJsonPath('data.what_it_does.price', ['mode' => 'percent', 'percent' => '10.0000', 'amount_egp' => null, 'funded_by' => 'commission'])
            ->assertJsonPath('data.what_it_does.gives_spread', false);

        $this->postJson(BPD_CODES, ['code' => 'DIA500', 'side' => 'buyer', 'product_type' => 'diamond', 'price_amount_egp' => 500, 'discount_funded_by' => 'commission'], Promo::key())
            ->assertCreated()->assertJsonPath('data.what_it_does.deposit.mode', 'none');
    });

    it('refuses a discount with no payer, the spread on stones, and two things at once', function () {
        $buyer = ['side' => 'buyer', 'product_type' => 'gold'];
        $this->postJson(BPD_CODES, ['code' => 'NOPAYER', 'price_percent' => 10] + $buyer, Promo::key())
            ->assertStatus(422)->assertJsonValidationErrors('discount_funded_by');
        $this->postJson(BPD_CODES, ['code' => 'DIASPREAD', 'side' => 'buyer', 'product_type' => 'diamond', 'price_percent' => 10, 'discount_funded_by' => 'spread'], Promo::key())
            ->assertStatus(422)->assertJsonValidationErrors('discount_funded_by');
        $this->postJson(BPD_CODES, ['code' => 'BOTH', 'side' => 'buyer', 'product_type' => 'diamond', 'price_percent' => 10, 'deposit_percent' => 10, 'discount_funded_by' => 'commission'], Promo::key())
            ->assertStatus(422)->assertJsonValidationErrors('price_percent');
        $this->postJson(BPD_CODES, ['code' => 'SELLERPRICE', 'side' => 'seller', 'product_type' => 'gold', 'commission_mode' => 'waive', 'price_percent' => 10, 'discount_funded_by' => 'commission'], Promo::key())
            ->assertStatus(422)->assertJsonValidationErrors('price_percent');
    });
});

describe('on a sale', function () {
    beforeEach(function () {
        Storage::fake('identity_private');
        Notification::fake();
        Orders::workedPrices();
        $this->seed(DashboardRolesAndPermissionsSeeder::class);
        $this->listing = Orders::ring();
        $this->buyer = BuyRequests::funded('60000');
    });

    function buyerPriceCode(string $code, array $terms): void
    {
        Promo::code(['code' => $code, 'kind' => 'buyer', 'side' => 'buyer', 'product_type' => 'gold', 'commission_mode' => 'none',
            'commission_waived' => false, 'gives_spread' => false] + $terms);
    }

    function paidWithCode($test, string $code): Order
    {
        $id = (string) BuyRequests::send($test, $test->buyer, $test->listing, ['promo_code' => $code])->assertCreated()->json('data.id');
        $seller = Customer::query()->findOrFail($test->listing->seller_id);
        BuyRequests::accept($test, $seller, $test->listing, $id, BuyRequests::branchOf($test->listing))->assertCreated();
        $order = Order::query()->where('buy_request_id', $id)->firstOrFail();
        Orders::inspected($test, $order);
        $test->due = Orders::show($test, $test->buyer, $order)->json('data.amount_due');
        $test->before = BuyRequests::balances($test->buyer)['available'];
        Orders::pay($test, $test->buyer, $order)->assertOk();

        return $order->refresh();
    }

    function sumOf(Order $order, string $kind): string
    {
        $sum = '0';
        foreach (Orders::lines($order, 'balance_payment') as $line) {
            if ($line->kind === $kind) {
                $sum = bcadd($sum, $line->amount, 4);
            }
        }

        return bcadd($sum, '0', 4);
    }

    it('previews the discount without recording a use, and refuses a wrong code with its reason', function () {
        buyerPriceCode('SPREAD100', ['price_mode' => 'amount', 'price_amount_egp' => '100', 'discount_funded_by' => 'spread']);

        $data = Listings::as($this, $this->buyer)->postJson(BPD_PREVIEW, ['listing_id' => $this->listing->listing_id, 'promo_code' => 'spread100'])
            ->assertOk()->json('data');
        expect($data['effect'])->toBe('price')->and($data['discount_egp'])->toBe('100.0000')
            ->and($data['total_egp'])->toBe(bcsub($data['price_before_egp'], '100', 4))
            ->and($data['deposit_egp'])->toBe($data['deposit_full_egp']);

        Listings::as($this, $this->buyer)->postJson(BPD_PREVIEW, ['listing_id' => $this->listing->listing_id, 'promo_code' => 'NOPE'])
            ->assertStatus(422)->assertJsonPath('code', 'promo_code_invalid')->assertJsonPath('details.reason', 'inactive');
        expect(PromoCodeUse::query()->count())->toBe(0);
    });

    it('takes an amount paid by the spread off what the buyer pays, and out of Dahab\'s spread', function () {
        buyerPriceCode('SPREAD100', ['price_mode' => 'amount', 'price_amount_egp' => '100', 'discount_funded_by' => 'spread']);
        $order = paidWithCode($this, 'SPREAD100');

        expect($order->price_discount_egp)->toBe('100.0000')->and($order->price_discount_funded_by)->toBe('spread')
            ->and(bcsub($this->before, BuyRequests::balances($this->buyer)['available'], 4))->toBe($this->due)
            ->and(sumOf($order, 'dahab_spread'))->toBe(bcsub($order->spread_amount, '100', 4))
            ->and(sumOf($order, 'dahab_commission'))->toBe($order->commission_amount);

        $invoice = TaxInvoice::query()->where('order_id', $order->order_id)->where('party_role', 'buyer')->sole();
        expect((string) $invoice->net_amount)->toBe(bcsub($order->final_buyer_total, '100', 4))
            ->and($invoice->lines['promo_discount'])->toBe('100.0000')->and($invoice->lines['promo_code'])->toBe('SPREAD100');

        $promo = Orders::show($this, $this->buyer, $order)->json('data.promo');
        expect($promo['discount_egp'])->toBe('100.0000')->and($promo['price_discount']['amount_egp'])->toBe('100.0000');
    });

    it('caps a percentage paid by the commission at the commission, and keeps the seller\'s invoice whole', function () {
        buyerPriceCode('GOLD10', ['price_mode' => 'percent', 'price_percent' => '10', 'discount_funded_by' => 'commission']);
        $order = paidWithCode($this, 'GOLD10');

        // 10 % of ~55,600 is far above the ring's commission (516 net of VAT): the discount stops there.
        expect($order->price_discount_egp)->toBe($order->commission_ordinary_amount)
            ->and(sumOf($order, 'dahab_commission'))->toBe('0.0000')
            ->and(sumOf($order, 'dahab_spread'))->toBe($order->spread_amount);

        $seller = TaxInvoice::query()->where('order_id', $order->order_id)->where('party_role', 'seller')->sole();
        expect((string) $seller->net_amount)->toBe($order->commission_amount)->and((string) $seller->vat_amount)->toBe($order->vat_amount);
    });
});
