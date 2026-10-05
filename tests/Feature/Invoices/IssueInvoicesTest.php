<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TaxInvoice;
use App\Support\Invoices\IssueTaxInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US1, FR-001–FR-007: the balance payment issues both tax invoices in
// its own transaction — the seller's for the commission and VAT posted, the
// buyer's for the price paid with VAT 0 — never twice, never without the
// settlement.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('issues the seller and buyer invoices at payment, equal to what was posted', function () {
    $order = Invoices::paid($this);
    $seller = Invoices::of($order, 'seller');
    $buyer = Invoices::of($order, 'buyer');

    expect(TaxInvoice::query()->where('order_id', $order->order_id)->count())->toBe(2)
        ->and($seller->invoice_no)->toBe($order->order_ref.'-S')
        ->and($seller->customer_id)->toBe($order->seller_id)
        ->and((string) $seller->net_amount)->toBe('600.0000')
        ->and((string) $seller->vat_amount)->toBe('84.0000')
        ->and((string) $seller->gross_amount)->toBe('684.0000')
        ->and((float) $seller->vat_rate)->toBe(14.0)
        ->and($seller->lines['weight_g'])->toBe('10.000')
        ->and($seller->lines['unit_rate'])->toBe('5236.8750')
        ->and($seller->lines['gold_value'])->toBe('52368.7500')
        ->and($seller->lines['making_total'])->toBe('3000.0000')
        ->and($seller->lines['subtotal'])->toBe('55368.7500')
        ->and($seller->lines['paid_to_wallet'])->toBe('54684.7500')
        ->and($seller->lines['karat_label'])->toBe('21K')
        ->and($seller->lines['piece_type_en'])->not->toBeNull()
        ->and($buyer->invoice_no)->toBe($order->order_ref.'-B')
        ->and($buyer->customer_id)->toBe($order->buyer_id)
        ->and((string) $buyer->net_amount)->toBe('55631.2500')
        ->and((string) $buyer->vat_amount)->toBe('0.0000')
        ->and((string) $buyer->gross_amount)->toBe('55631.2500')
        ->and($buyer->lines['unit_rate'])->toBe('5263.1250')
        ->and($buyer->lines['gold_value'])->toBe('52631.2500')
        ->and($buyer->lines)->not->toHaveKey('paid_to_wallet')
        ->and($seller->getAttributes()['eta_reference'] ?? null)->toBeNull();

    expect(AuditLog::query()->where('action', 'order.paid')->sole()->after_json['invoice_numbers'] ?? null)
        ->toBe([$order->order_ref.'-S', $order->order_ref.'-B']);
});

it('issues invoices for stone pieces on the asking price with no spread', function () {
    $diamond = Invoices::paidDiamond($this);
    $seller = Invoices::of($diamond, 'seller');

    expect((string) $seller->net_amount)->toBe('6000.0000')
        ->and((string) $seller->vat_amount)->toBe('840.0000')
        ->and($seller->lines['asking_price'])->toBe('120000.0000')
        ->and($seller->lines['gold_value'])->toBeNull()
        ->and((string) Invoices::of($diamond, 'buyer')->gross_amount)->toBe('120000.0000');

    $mixed = Invoices::paidGoldWithDiamond($this);
    expect((string) Invoices::of($mixed, 'seller')->net_amount)->toBe((string) $mixed->commission_amount)
        ->and((string) Invoices::of($mixed, 'buyer')->net_amount)->toBe((string) $mixed->final_buyer_total);
});

it('keeps an issued invoice as it was when the VAT setting changes later', function () {
    $order = Invoices::paid($this);
    Setting::query()->where('setting_key', 'vat.pct')->update(['value_numeric' => '15']);

    $seller = Invoices::of($order, 'seller');
    expect((string) $seller->vat_amount)->toBe('84.0000')->and((float) $seller->vat_rate)->toBe(14.0);
});

it('issues nothing more on a replayed payment', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $key = (string) Str::uuid();
    Orders::pay($this, Orders::buyer($order), $order, $key)->assertOk();
    Orders::pay($this, Orders::buyer($order), $order, $key)->assertOk();
    Orders::pay($this, Orders::buyer($order), $order)->assertStatus(409);

    expect(TaxInvoice::query()->where('order_id', $order->order_id)->count())->toBe(2);
});

it('rolls the whole payment back when the invoices cannot be written', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $this->mock(IssueTaxInvoices::class)->shouldReceive('issue')->andThrow(new RuntimeException('invoice store down'));

    Orders::pay($this, Orders::buyer($order), $order)->assertStatus(500);

    expect($order->refresh()->state->value)->toBe('awaiting_balance')
        ->and($order->settlement_txn_id)->toBeNull()
        ->and(Orders::lines($order, 'balance_payment'))->toBe([])
        ->and(TaxInvoice::query()->count())->toBe(0);
});

it('copies Dahab\'s details at issue when configured, and pays anyway when not', function () {
    $without = Invoices::paid($this);
    expect(Invoices::of($without, 'seller')->issuer)->toBeNull();

    Invoices::configureIssuer();
    $with = Invoices::paid($this);
    expect(Invoices::of($with, 'seller')->issuer['tax_registration_no'])->toBe('100-200-300');
});
