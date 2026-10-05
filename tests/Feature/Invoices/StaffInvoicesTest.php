<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US2, FR-010–FR-014, FR-024: Finance lists every invoice with the
// figures, filters and a derived status, opens one with its credit notes,
// downloads the PDF (audited), and lists the credit notes.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Invoices::configureIssuer();
    Invoices::fakeRenderer();
    $this->first = Invoices::paid($this);
    $this->second = Invoices::paid($this);
});

it('lists every invoice newest first with the month and period figures', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Invoices::credit($this, Invoices::of($this->first, 'seller'), '114')->assertCreated();

    $res = $this->getJson(Invoices::STAFF_URL)->assertOk()->assertJsonCount(4, 'data');
    $rows = collect($res->json('data'));

    expect($rows->first()['order_ref'])->toBe($this->second->order_ref)
        ->and($rows->pluck('party')->unique()->sort()->values()->all())->toBe(['buyer', 'seller'])
        ->and($rows->firstWhere('number', $this->first->order_ref.'-S'))->toMatchArray([
            'net' => '600.0000', 'vat' => '84.0000', 'gross' => '684.0000', 'credited' => '114.0000',
            'remaining' => '570.0000', 'status' => 'partly_credited', 'document_ready' => true,
        ])
        ->and($rows->firstWhere('number', $this->first->order_ref.'-S')['customer']['id'])->toBe($this->first->seller_id)
        ->and($rows->firstWhere('number', $this->first->order_ref.'-B')['vat'])->toBe('0.0000');

    // 114 at 14% = 100 net + 14 VAT; VAT collected = 2 × 84 − 14.
    foreach (['month', 'period'] as $window) {
        expect($res->json("meta.figures.{$window}"))->toBe([
            'issued_count' => 4, 'net_invoiced' => '1200.0000', 'vat_collected' => '154.0000',
            'credit_notes_count' => 1, 'credit_notes_amount' => '114.0000',
        ]);
    }
});

it('filters by party, status, search and period, a page at a time', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Invoices::credit($this, Invoices::of($this->first, 'seller'), '684')->assertCreated();

    $this->getJson(Invoices::STAFF_URL.'?party=buyer')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(Invoices::STAFF_URL.'?status=credited')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $this->first->order_ref.'-S');
    $this->getJson(Invoices::STAFF_URL.'?status=issued')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(Invoices::STAFF_URL.'?q='.$this->second->order_ref)->assertOk()->assertJsonCount(2, 'data');

    $seller = Customer::query()->findOrFail($this->second->seller_id);
    $this->getJson(Invoices::STAFF_URL.'?q='.$seller->display_ref)->assertOk()->assertJsonCount(1, 'data');

    $old = now('Africa/Cairo')->subDays(100)->toDateString();
    $this->getJson(Invoices::STAFF_URL."?from={$old}&to={$old}")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(Invoices::STAFF_URL.'?from=2026-01-10&to=2026-01-01')->assertStatus(422);
    $this->getJson(Invoices::STAFF_URL.'?from=2025-01-01&to=2026-06-01')->assertStatus(422);

    $page = $this->getJson(Invoices::STAFF_URL.'?per_page=3')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(Invoices::STAFF_URL.'?per_page=3&cursor='.$page->json('meta.next_cursor'))->assertOk()->assertJsonCount(1, 'data');
});

it('opens one invoice with its lines, details and credit notes', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $invoice = Invoices::of($this->first, 'seller');
    Invoices::credit($this, $invoice, '100', 'A first partial correction.')->assertCreated();

    $this->getJson(Invoices::STAFF_URL."/{$invoice->invoice_id}")->assertOk()
        ->assertJsonPath('data.number', $invoice->invoice_no)
        ->assertJsonPath('data.lines.paid_to_wallet', '54684.7500')
        ->assertJsonPath('data.issuer.legal_name_en', 'Dahab Test Company')
        ->assertJsonPath('data.party_details.display_ref', Customer::query()->findOrFail($this->first->seller_id)->display_ref)
        ->assertJsonPath('data.creditable', true)
        ->assertJsonPath('data.credit_notes.0.reason', 'A first partial correction.')
        ->assertJsonPath('data.credit_notes.0.issued_by.id', $finance->staff_id)
        ->assertJsonMissingPath('data.eta_reference');

    $this->getJson(Invoices::STAFF_URL.'/'.Invoices::of($this->first, 'buyer')->invoice_id)->assertOk()->assertJsonPath('data.creditable', false);
    $this->getJson(Invoices::STAFF_URL.'/00000000-0000-0000-0000-000000000000')->assertNotFound();
});

it('downloads a PDF and audits the view; a pending document is not ready', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $invoice = Invoices::of($this->first, 'seller');

    $res = $this->get(Invoices::STAFF_URL."/{$invoice->invoice_id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($res->headers->get('Content-Disposition'))->toContain($invoice->invoice_no.'.pdf')
        ->and($res->getContent())->toStartWith('%PDF-fake')
        ->and(AuditLog::query()->where('action', 'invoice.document_viewed')->sole()->actor_staff_id)->toBe($finance->staff_id);

    config(['dahab-invoices.issuer.legal_name_en' => '']);
    $third = Invoices::paid($this);
    Finance::actAs($finance);
    $this->getJson(Invoices::STAFF_URL.'/'.Invoices::of($third, 'seller')->invoice_id.'/pdf')->assertStatus(409)
        ->assertJsonPath('code', 'document_not_ready');
    expect(AuditLog::query()->where('action', 'invoice.document_viewed')->count())->toBe(1);
});

it('lists the credit notes with what they reverse, and downloads one (audited)', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $invoice = Invoices::of($this->first, 'seller');
    $note = Invoices::credit($this, $invoice, '50', 'Weight adjusted after inspection.')->assertCreated()->json('data');

    $this->getJson(Invoices::CREDIT_NOTES_URL)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', $note['number'])
        ->assertJsonPath('data.0.invoice_number', $invoice->invoice_no)
        ->assertJsonPath('data.0.reason', 'Weight adjusted after inspection.')
        ->assertJsonPath('data.0.gross', '50.0000')
        ->assertJsonPath('data.0.issued_by.id', $finance->staff_id);
    $this->getJson(Invoices::CREDIT_NOTES_URL.'?q=nothing-matches')->assertOk()->assertJsonCount(0, 'data');

    $this->get(Invoices::CREDIT_NOTES_URL."/{$note['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(AuditLog::query()->where('action', 'credit_note.document_viewed')->count())->toBe(1);
});

it('exports the filtered invoices as CSV, audited, truncated at the cap', function () {
    Finance::staff($this, SeedRole::FINANCE);

    $res = $this->get(Invoices::STAFF_URL.'/export?party=seller', ['Accept' => 'application/json'])->assertOk();
    $csv = $res->getContent();
    $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($lines[0])->toContain('Number,Date,Order,Party')
        ->and($lines)->toHaveCount(3)
        ->and($csv)->toContain($this->first->order_ref.'-S')
        ->and($csv)->not->toContain($this->first->order_ref.'-B')
        ->and($res->headers->get('X-Export-Truncated'))->toBe('false');

    $audit = AuditLog::query()->where('action', 'invoices.exported')->sole();
    expect($audit->after_json['party'] ?? null)->toBe('seller')->and($audit->after_json['rows'] ?? null)->toBe(2);

    config(['dahab-invoices.export_cap' => 1]);
    $truncated = $this->get(Invoices::STAFF_URL.'/export', ['Accept' => 'application/json'])->assertOk();
    expect($truncated->headers->get('X-Export-Truncated'))->toBe('true');
});
