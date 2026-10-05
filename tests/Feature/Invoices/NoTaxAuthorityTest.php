<?php

use App\Enums\SeedRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US5, FR-022: nothing is filed with the Egyptian Tax Authority, so
// no response and no document may claim a filing or registration status.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Invoices::configureIssuer();
    Invoices::fakeRenderer();
});

it('never exposes a Tax Authority reference or status', function () {
    $order = Invoices::paid($this);
    $seller = Invoices::of($order, 'seller');

    expect($seller->getAttributes()['eta_reference'])->toBeNull();

    Finance::staff($this, SeedRole::FINANCE);
    $bodies = [
        $this->getJson(Invoices::STAFF_URL)->assertOk()->getContent(),
        $this->getJson(Invoices::STAFF_URL."/{$seller->invoice_id}")->assertOk()->getContent(),
        $this->getJson(Invoices::CREDIT_NOTES_URL)->assertOk()->getContent(),
        Listings::as($this, Orders::seller($order))->getJson(Invoices::CUSTOMER_URL."/{$seller->invoice_id}")->assertOk()->getContent(),
    ];

    foreach ($bodies as $body) {
        expect($body)->not->toContain('eta_reference')
            ->and(strtolower($body))->not->toContain('tax authority')
            ->and($body)->not->toContain('"registered"')
            ->and($body)->not->toContain('"rejected"');
    }
});

it('prints no filing claim on the documents', function () {
    foreach (['invoice', 'credit-note', '_issuer'] as $view) {
        $source = file_get_contents(resource_path("views/documents/{$view}.blade.php"));
        expect(strtolower($source))->not->toContain('tax authority')
            ->and($source)->not->toContain('مصلحة الضرائب')
            ->and(strtolower($source))->not->toContain('filed');
    }
});
