<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US3, FR-015–FR-017: each party reads and downloads only their own
// invoice (and its credit notes); the order and the wallet line point to it;
// reading needs the verified gate only and is not audited.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Invoices::configureIssuer();
    Invoices::fakeRenderer();
    $this->order = Invoices::paid($this);
    $this->seller = Customer::query()->findOrFail($this->order->seller_id);
    $this->buyer = Customer::query()->findOrFail($this->order->buyer_id);
});

it('lists the seller\'s sale and the buyer\'s purchase, each their own', function () {
    $sold = Listings::as($this, $this->seller)->getJson(Invoices::CUSTOMER_URL)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->order->order_ref.'-S')
        ->assertJsonPath('data.0.party', 'seller')
        ->assertJsonPath('data.0.gross', '684.0000')
        ->assertJsonPath('data.0.piece.karat', 21)
        ->assertJsonPath('data.0.piece.subtotal', '55368.7500');
    expect($sold->json('data.0'))->not->toHaveKey('customer');

    Listings::as($this, $this->buyer)->getJson(Invoices::CUSTOMER_URL.'?role=buyer')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->order->order_ref.'-B')
        ->assertJsonPath('data.0.vat', '0.0000');
    Listings::as($this, $this->buyer)->getJson(Invoices::CUSTOMER_URL.'?role=seller')->assertOk()->assertJsonCount(0, 'data');
});

it('opens and downloads an own invoice and its credit notes, never anyone else\'s', function () {
    $seller = Invoices::of($this->order, 'seller');
    Finance::staff($this, SeedRole::FINANCE);
    $note = Invoices::credit($this, $seller, '100')->assertCreated()->json('data');

    Listings::as($this, $this->seller)->getJson(Invoices::CUSTOMER_URL."/{$seller->invoice_id}")->assertOk()
        ->assertJsonPath('data.lines.paid_to_wallet', '54684.7500')
        ->assertJsonPath('data.issuer.legal_name_ar', 'شركة دهب للاختبار')
        ->assertJsonPath('data.status', 'partly_credited')
        ->assertJsonPath('data.credit_notes.0.number', $note['number'])
        ->assertJsonMissingPath('data.credit_notes.0.issued_by');

    $pdf = Listings::as($this, $this->seller)->get(Invoices::CUSTOMER_URL."/{$seller->invoice_id}/pdf")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toContain($seller->invoice_no.'.pdf');
    Listings::as($this, $this->seller)->get("/api/v1/customer/me/credit-notes/{$note['id']}/pdf")->assertOk();

    // The buyer can see neither the seller's invoice, its PDF, nor the credit note.
    Listings::as($this, $this->buyer)->getJson(Invoices::CUSTOMER_URL."/{$seller->invoice_id}")->assertNotFound();
    Listings::as($this, $this->buyer)->getJson(Invoices::CUSTOMER_URL."/{$seller->invoice_id}/pdf")->assertNotFound();
    Listings::as($this, $this->buyer)->getJson("/api/v1/customer/me/credit-notes/{$note['id']}/pdf")->assertNotFound();

    expect(AuditLog::query()->whereIn('action', ['invoice.document_viewed', 'credit_note.document_viewed'])->count())->toBe(0);
});

it('answers not ready while the document is being made', function () {
    config(['dahab-invoices.issuer.address_en' => '']);
    $order = Invoices::paid($this);
    $buyer = Customer::query()->findOrFail($order->buyer_id);

    Listings::as($this, $buyer)->getJson(Invoices::CUSTOMER_URL.'/'.Invoices::of($order, 'buyer')->invoice_id.'/pdf')
        ->assertStatus(409)->assertJsonPath('code', 'document_not_ready');
});

it('links the paid order and the wallet line to the caller\'s own invoice', function () {
    Orders::show($this, $this->seller, $this->order)->assertOk()
        ->assertJsonPath('data.invoice.number', $this->order->order_ref.'-S');
    Orders::show($this, $this->buyer, $this->order)->assertOk()
        ->assertJsonPath('data.invoice.number', $this->order->order_ref.'-B');

    $unpaid = Orders::accepted($this);
    Orders::show($this, Orders::buyer($unpaid), $unpaid)->assertOk()->assertJsonPath('data.invoice', null);

    $line = collect(Listings::as($this, $this->buyer)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->json('data'))
        ->firstWhere('kind', 'balance_payment');
    expect($line['invoice_id'])->toBe(Invoices::of($this->order, 'buyer')->invoice_id);
});

it('lets a suspended customer read, but not an unverified one', function () {
    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);
    Listings::as($this, $this->seller)->getJson(Invoices::CUSTOMER_URL)->assertOk()->assertJsonCount(1, 'data');

    $unverified = Customer::factory()->pendingVerification()->create();
    Listings::as($this, $unverified)->getJson(Invoices::CUSTOMER_URL)->assertForbidden()->assertJsonPath('code', 'verification_required');
});
