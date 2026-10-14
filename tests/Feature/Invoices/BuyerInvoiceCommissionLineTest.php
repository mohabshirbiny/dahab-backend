<?php

use App\Enums\AccountKind;
use App\Enums\PartyRole;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Support\Invoices\InvoiceLines;
use App\Support\Orders\SettlementFigures;
use App\Support\Pricing\PricingRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Package C: the seller's commission is VAT-inclusive (net + VAT = the rate), and item 13: on the
// buyer's gold invoice the price difference is shown as Dahab's commission (VAT 0), equal to the
// spread posted; the totals never change.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('carves VAT out of the seller commission and shows the buyer the price difference as commission', function () {
    $order = Invoices::paid($this);
    $seller = Invoices::of($order, 'seller');
    $buyer = Invoices::of($order, 'buyer');

    // §3.3 ring: 20 % of the 3,000 making charge = 600, VAT included.
    expect((string) $seller->net_amount)->toBe('526.3158')
        ->and((string) $seller->vat_amount)->toBe('73.6842')
        ->and((string) $seller->gross_amount)->toBe('600.0000')
        ->and(bcadd((string) $seller->net_amount, (string) $seller->vat_amount, 4))->toBe((string) $seller->gross_amount)
        ->and($seller->lines['commission_vat_inclusive'])->toBeTrue()
        ->and($seller->lines['paid_to_wallet'])->toBe('54768.7500')
        ->and((string) $order->seller_proceeds)->toBe('54768.7500');

    $l = $buyer->lines;
    expect($l['seller_unit_rate'])->toBe('5236.8750')
        ->and($l['gold_value_at_seller_rate'])->toBe('52368.7500')
        ->and($l['price_difference'])->toBe('262.5000')
        ->and(bcadd(bcadd($l['gold_value_at_seller_rate'], $l['price_difference'], 4), $l['making_total'], 4))->toBe($l['subtotal'])
        ->and((string) $buyer->gross_amount)->toBe('55631.2500')
        ->and((string) $buyer->vat_amount)->toBe('0.0000');

    // The line is the spread posted to dahab_spread; the commission and VAT lines are the invoice's.
    $posted = collect(Orders::lines($order, 'balance_payment'))->mapWithKeys(fn ($r) => [$r->kind => $r->amount]);
    expect($posted[AccountKind::DAHAB_SPREAD->value])->toBe($l['price_difference'])
        ->and($posted[AccountKind::DAHAB_COMMISSION->value])->toBe('526.3158')
        ->and($posted[AccountKind::VAT_PAYABLE->value])->toBe('73.6842');
});

it('shows no price difference on a diamond invoice', function () {
    $diamond = Invoices::paidDiamond($this);

    expect(Invoices::of($diamond, 'buyer')->lines)->not->toHaveKey('price_difference')
        ->and((string) Invoices::of($diamond, 'seller')->gross_amount)->toBe('6000.0000');
});

it('leaves the line out for a market-maker purchase and when the rates crossed', function () {
    $listing = Listing::factory()->make(['category' => 'gold', 'karat_code' => 21, 'making_charge_per_g' => '300.00']);
    $order = new Order(['locked_seller_unit_rate' => '5236.8750']);
    $rates = new PricingRates('20', '5', '200', '14');
    $figures = fn (string $buyerTotal) => new SettlementFigures(
        weight: '10.000', buyerTotal: $buyerTotal, sellerGross: '55368.7500', commission: '526.3158', vat: '73.6842',
        spread: bcsub($buyerTotal, '55368.7500', 4), proceeds: '54768.7500', deposit: '1000.0000', balance: '0', excess: '0',
    );
    $lines = app(InvoiceLines::class);

    $dealer = new BuyRequest(['promo_code' => 'DEALER-1', 'locked_unit_rate' => '5263.1250']);
    $crossed = new BuyRequest(['promo_code' => null, 'locked_unit_rate' => '5200.0000']);
    $plain = new BuyRequest(['promo_code' => null, 'locked_unit_rate' => '5263.1250']);

    expect($lines->build(PartyRole::BUYER, $order, $listing, $dealer, $figures('55631.2500'), $rates))->not->toHaveKey('price_difference')
        ->and($lines->build(PartyRole::BUYER, $order, $listing, $crossed, $figures('55000.0000'), $rates))->not->toHaveKey('price_difference')
        ->and($lines->build(PartyRole::BUYER, $order, $listing, $plain, $figures('55631.2500'), $rates)['price_difference'])->toBe('262.5000');
});

it('still renders invoices issued before the change', function () {
    $old = new TaxInvoice([
        'invoice_no' => 'DH-2026-000001-B', 'party_role' => 'buyer', 'net_amount' => '55631.2500', 'vat_amount' => '0', 'gross_amount' => '55631.2500',
        'vat_rate' => '14', 'issued_at' => now(), 'party' => ['full_name' => 'Old Buyer', 'display_ref' => '000001'],
        'lines' => ['category' => 'gold', 'karat' => 21, 'karat_label' => '21K', 'piece_type_en' => 'Ring', 'piece_type_ar' => 'خاتم', 'weight_g' => '10.000',
            'unit_rate' => '5263.1250', 'gold_value' => '52631.2500', 'making_total' => '3000.0000', 'asking_price' => null, 'subtotal' => '55631.2500'],
        'issuer' => null,
    ]);
    $oldSeller = new TaxInvoice([
        'invoice_no' => 'DH-2026-000001-S', 'party_role' => 'seller', 'net_amount' => '600.0000', 'vat_amount' => '84.0000', 'gross_amount' => '684.0000',
        'vat_rate' => '14', 'issued_at' => now(), 'party' => ['full_name' => 'Old Seller', 'display_ref' => '000002'],
        'lines' => ['category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'unit_rate' => '5236.8750', 'gold_value' => '52368.7500',
            'making_total' => '3000.0000', 'subtotal' => '55368.7500', 'commission_pct' => '20', 'commission_minimum' => '200', 'paid_to_wallet' => '54768.7500'],
        'issuer' => null,
    ]);

    $buyerHtml = view('documents.invoice', ['invoice' => $old])->render();
    $sellerHtml = view('documents.invoice', ['invoice' => $oldSeller])->render();

    expect($buyerHtml)->toContain('Gold value at 5,263.13 per gram')->not->toContain('>Commission<')
        ->and($sellerHtml)->toContain('Total charges')->not->toContain('Commission before VAT');
});
