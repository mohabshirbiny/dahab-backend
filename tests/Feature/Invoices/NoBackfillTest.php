<?php

use App\Enums\SeedRole;
use App\Models\BuyRequest;
use App\Models\Listing;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Support\Invoices\InvoiceLines;
use App\Support\Invoices\IssuerDetails;
use App\Support\Invoices\IssueTaxInvoices;
use App\Support\Orders\SettlementFigures;
use App\Support\Pricing\PricingRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US6, FR-021: orders paid before spec 016 get no invoice; every
// screen handles a paid order without one.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Invoices::configureIssuer();
    Invoices::fakeRenderer();
});

it('leaves an order paid before installation without an invoice, and nothing breaks', function () {
    // "Before installation": the payment ran without issuing (analysis L1).
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    $noop = new class(app(InvoiceLines::class), app(IssuerDetails::class)) extends IssueTaxInvoices
    {
        public function issue(Order $order, Listing $listing, BuyRequest $request, SettlementFigures $f, PricingRates $rates): array
        {
            return [];
        }
    };
    app()->instance(IssueTaxInvoices::class, $noop);
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();
    app()->forgetInstance(IssueTaxInvoices::class);

    expect(TaxInvoice::query()->count())->toBe(0);

    Orders::show($this, Orders::buyer($order), $order)->assertOk()->assertJsonPath('data.invoice', null);
    Orders::show($this, Orders::seller($order), $order)->assertOk()->assertJsonPath('data.invoice', null);

    Artisan::call('invoices:render-pending');
    expect(TaxInvoice::query()->count())->toBe(0);

    Finance::staff($this, SeedRole::FINANCE);
    $this->getJson(Invoices::STAFF_URL)->assertOk()->assertJsonCount(0, 'data');
});
