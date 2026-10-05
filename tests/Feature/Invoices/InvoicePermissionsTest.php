<?php

use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 FR-009, Part 1 §4.2: invoice.view and invoice.correct belong to the
// CEO and Finance — never the COO, Operations, Verification or IGI.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    Invoices::configureIssuer();
    Invoices::fakeRenderer();
    $this->order = Invoices::paid($this);
    $this->invoice = Invoices::of($this->order, 'seller');
});

function inv016Reads(object $test, string $invoiceId): array
{
    return [
        $test->getJson(Invoices::STAFF_URL)->status(),
        $test->get(Invoices::STAFF_URL.'/export', ['Accept' => 'application/json'])->status(),
        $test->getJson(Invoices::STAFF_URL."/{$invoiceId}")->status(),
        $test->getJson(Invoices::STAFF_URL."/{$invoiceId}/pdf")->status(),
        $test->getJson(Invoices::CREDIT_NOTES_URL)->status(),
    ];
}

it('opens the invoice pages to the CEO and Finance only', function () {
    foreach ([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::VERIFICATION, SeedRole::IGI_BRANCH] as $role) {
        Finance::staff($this, $role);
        expect(inv016Reads($this, $this->invoice->invoice_id))->toBe([403, 403, 403, 403, 403]);
        Invoices::credit($this, $this->invoice, '10')->assertForbidden();
    }

    Finance::staff($this, SeedRole::FINANCE);
    expect(inv016Reads($this, $this->invoice->invoice_id))->toBe([200, 200, 200, 200, 200]);
    Invoices::credit($this, $this->invoice, '10')->assertCreated();

    Finance::staff($this, SeedRole::CEO);
    expect(inv016Reads($this, $this->invoice->invoice_id))->toBe([200, 200, 200, 200, 200]);
    Invoices::credit($this, $this->invoice, '10')->assertCreated();
});

it('refuses a frozen staff member', function () {
    $ceo = Finance::staff($this, SeedRole::CEO);
    $finance = Finance::staff($this, SeedRole::FINANCE);
    AccountFreeze::query()->create(['frozen_staff_id' => $finance->staff_id, 'frozen_by' => $ceo->staff_id, 'frozen_at' => now()]);

    $this->getJson(Invoices::STAFF_URL)->assertForbidden()->assertJsonPath('code', 'account_frozen');
    Invoices::credit($this, $this->invoice, '10')->assertForbidden()->assertJsonPath('code', 'account_frozen');
});

it('never lets a customer token reach the staff surface', function () {
    $this->withToken(Listings::token(Orders::seller($this->order)))
        ->getJson(Invoices::STAFF_URL)->assertUnauthorized();
});
