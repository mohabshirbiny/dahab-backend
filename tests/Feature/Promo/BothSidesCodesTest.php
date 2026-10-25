<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\TaxInvoice;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// docs/features/offer-details-and-invoices.md: one gold sale where the seller's code waives the
// commission and the buyer's code gives the seller's price. Both parties see what their code did,
// the buyer pays exactly the discounted figure, and both get an invoice.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Promo::open();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);

    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Orders::ring($this->seller);
    Promo::code(['code' => 'SELLER2', 'commission_mode' => 'waive', 'product_type' => 'gold']);
    Promo::code(['code' => 'BUYER', 'kind' => 'buyer', 'side' => 'buyer', 'product_type' => 'gold',
        'commission_mode' => 'none', 'commission_waived' => false, 'gives_spread' => true]);
});

function bothCodesOrder($test, string $funds = '60000'): Order
{
    Listings::as($test, $test->seller)->putJson("/api/v1/customer/me/listings/{$test->listing->listing_id}/promo", ['code' => 'seller2'], Promo::key())
        ->assertOk();

    $test->buyer = BuyRequests::funded($funds);
    $id = (string) BuyRequests::send($test, $test->buyer, $test->listing, ['promo_code' => 'buyer'])->assertCreated()->json('data.id');
    BuyRequests::accept($test, $test->seller, $test->listing, $id, BuyRequests::branchOf($test->listing))->assertCreated();

    return Order::query()->where('buy_request_id', $id)->firstOrFail();
}

it('shows the buyer the price, the discount and the total before and after inspection', function () {
    $order = bothCodesOrder($this);

    $promo = Orders::show($this, $this->buyer, $order)->assertOk()->json('data.promo');
    expect($promo['code'])->toBe('BUYER')->and($promo['gives_spread'])->toBeTrue()->and($promo['indicative'])->toBeTrue()
        ->and(bccomp($promo['discount_egp'], '0', 4))->toBe(1)
        ->and($promo['total_egp'])->toBe(bcsub($promo['price_before_egp'], $promo['discount_egp'], 4))
        ->and($promo['deposit_discount_egp'])->toBe('0.0000');

    Orders::inspected($this, $order);
    $data = Orders::show($this, $this->buyer, $order)->assertOk()->json('data');
    $balance = bcsub($data['final_total'], $data['deposit_amount'], 4);

    expect($data['promo']['indicative'])->toBeFalse()
        ->and($data['promo']['price_before_egp'])->toBe($data['final_total'])
        // What leaves the wallet is the balance less the discount.
        ->and($data['amount_due'])->toBe(bcsub($balance, $data['promo']['discount_egp'], 4));
});

it('takes exactly the discounted balance and issues both invoices', function () {
    $order = bothCodesOrder($this);
    Orders::inspected($this, $order);
    $due = Orders::show($this, $this->buyer, $order)->json('data.amount_due');
    $before = BuyRequests::balances($this->buyer)['available'];

    Orders::pay($this, $this->buyer, $order)->assertOk();
    $order->refresh();

    expect(bcsub($before, BuyRequests::balances($this->buyer)['available'], 4))->toBe($due)
        ->and($order->commission_amount)->toBe('0.0000')
        ->and(bccomp($order->spread_given_up, '0', 4))->toBe(1)
        ->and(bccomp($order->commission_ordinary_total, '0', 4))->toBe(1);

    $invoices = TaxInvoice::query()->where('order_id', $order->order_id)->get()
        ->keyBy(fn ($i) => $i->party_role instanceof BackedEnum ? $i->party_role->value : $i->party_role);

    $s = $invoices['seller'];
    expect($s->invoice_no)->toBe($order->order_ref.'-S')
        ->and((string) $s->gross_amount)->toBe('0.0000')
        ->and($s->lines['commission_waived'])->toEqual(['kind' => 'credit', 'code' => 'SELLER2', 'amount' => $order->commission_ordinary_total])
        ->and($s->lines['paid_to_wallet'])->toBe($order->seller_proceeds);

    $b = $invoices['buyer'];
    $paid = bcsub($order->final_buyer_total, $order->spread_given_up, 4);
    expect((string) $b->net_amount)->toBe($paid)
        ->and($b->lines['price_before_discount'])->toBe($order->final_buyer_total)
        ->and($b->lines['promo_discount'])->toBe($order->spread_given_up)
        ->and($b->lines['promo_code'])->toBe('BUYER')
        ->and($b->lines['total_paid'])->toBe($paid);

    $buyerView = Orders::show($this, $this->buyer, $order)->json('data');
    expect($buyerView['invoice']['number'])->toBe($order->order_ref.'-B')
        ->and($buyerView['promo']['discount_egp'])->toBe($order->spread_given_up)
        ->and($buyerView['promo']['total_egp'])->toBe($paid);

    // The seller sees the whole commission saved, VAT included.
    $sellerView = Orders::show($this, $this->seller, $order)->json('data');
    expect($sellerView['invoice']['number'])->toBe($order->order_ref.'-S')
        ->and($sellerView['commission']['code'])->toBe('SELLER2')
        ->and($sellerView['commission']['charged_total_egp'])->toBe('0.0000')
        ->and($sellerView['commission']['saved_total_egp'])->toBe($order->commission_ordinary_total)
        ->and(bccomp($sellerView['commission']['saved_total_egp'], $sellerView['commission']['saved_egp'], 4))->toBe(1);
});

it('reports the discounted figure when the wallet is short', function () {
    $order = bothCodesOrder($this, '20000');
    Orders::inspected($this, $order);
    $due = Orders::show($this, $this->buyer, $order)->json('data.amount_due');

    // Bring the buyer to 1 EGP short of what the payment takes.
    $available = BuyRequests::balances($this->buyer)['available'];
    expect(bccomp($available, $due, 4))->toBe(-1);
    Ledger::topUp($this->buyer, bcsub(bcsub($due, $available, 4), '1', 4));

    Orders::pay($this, $this->buyer, $order)->assertStatus(409)
        ->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('details.amount_due', $due)
        ->assertJsonPath('details.shortfall', '1.0000');
});
