<?php

use App\Models\Customer;
use App\Services\IdentityDocumentStorage;
use App\Support\Invoices\Documents\MpdfTaxDocumentRenderer;
use App\Support\Invoices\Documents\TaxDocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 FR-008, FR-023, FR-023a, research R5, R9, R10: the bilingual PDF is
// made after the payment commits, stored encrypted, once; Dahab's details and
// the party are copied once; missing details or a failing renderer never undo
// the payment, and the five-minute sweep heals them.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('stores each document encrypted once, copying the party and Dahab\'s details', function () {
    Invoices::configureIssuer();
    $fake = Invoices::fakeRenderer();
    $order = Invoices::paid($this);
    $seller = Invoices::of($order, 'seller');
    $sellerCustomer = Customer::query()->findOrFail($order->seller_id);

    expect($seller->storage_ref)->toBe("tax-documents/{$order->seller_id}/{$seller->invoice_no}.pdf.enc")
        ->and($seller->document_at)->not->toBeNull()
        ->and($seller->party)->toBe(['full_name' => $sellerCustomer->full_name, 'display_ref' => $sellerCustomer->display_ref])
        ->and($seller->issuer['legal_name_en'])->toBe('Dahab Test Company')
        ->and(app(IdentityDocumentStorage::class)->read($seller->storage_ref))->toStartWith('%PDF-fake '.$seller->invoice_no)
        ->and(Storage::disk('identity_private')->get($seller->storage_ref))->not->toContain('%PDF')
        ->and(Invoices::of($order, 'buyer')->storage_ref)->not->toBeNull()
        ->and($fake->rendered)->toHaveCount(2);

    Artisan::call('invoices:render-pending');
    expect($fake->rendered)->toHaveCount(2);
});

it('waits while Dahab\'s details are incomplete, then the sweep makes the documents', function () {
    $fake = Invoices::fakeRenderer();
    $order = Invoices::paid($this);

    expect(Invoices::of($order, 'seller')->storage_ref)->toBeNull()->and($fake->rendered)->toBe([]);

    Artisan::call('invoices:render-pending');
    expect(Invoices::of($order, 'seller')->storage_ref)->toBeNull();

    Invoices::configureIssuer();
    Artisan::call('invoices:render-pending');

    $seller = Invoices::of($order, 'seller');
    expect($seller->storage_ref)->not->toBeNull()
        ->and($seller->issuer['tax_registration_no'])->toBe('100-200-300')
        ->and($fake->rendered)->toHaveCount(2);
});

it('keeps the payment when the renderer fails, and heals later', function () {
    Invoices::configureIssuer();
    $fake = Invoices::fakeRenderer();
    $fake->fail = true;

    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    try {
        Orders::pay($this, Orders::buyer($order), $order);
    } catch (Throwable) {
        // A synchronous queue surfaces the job failure after the payment committed.
    }

    expect($order->refresh()->state->value)->toBe('ready_to_collect')
        ->and(Invoices::of($order, 'seller')->storage_ref)->toBeNull();

    $fake->fail = false;
    Artisan::call('invoices:render-pending');
    expect(Invoices::of($order, 'seller')->storage_ref)->not->toBeNull();
});

it('renders a real bilingual PDF with the invoice number and an Arabic-capable font', function () {
    Invoices::configureIssuer();
    app()->bind(TaxDocumentRenderer::class, MpdfTaxDocumentRenderer::class);
    Invoices::fakeRenderer();
    $order = Invoices::paid($this);
    $seller = Invoices::of($order, 'seller');
    $seller->party = ['full_name' => 'Mona Hassan', 'display_ref' => 'S-4417'];

    $pdf = app(MpdfTaxDocumentRenderer::class)->renderInvoice($seller);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(5000)
        // The title metadata carries the number (UTF-16BE); the Arabic glyphs are embedded from an Arabic-capable font.
        ->and($pdf)->toContain(mb_convert_encoding($seller->invoice_no, 'UTF-16BE', 'UTF-8'))
        ->and($pdf)->toMatch('/FontName \/[A-Z]+\+XBRiyaz/');
});
