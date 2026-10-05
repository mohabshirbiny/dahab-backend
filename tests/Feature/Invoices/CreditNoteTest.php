<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Enums\WalletEvent;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 US4, FR-018–FR-020: a credit note never edits the invoice; on a
// seller invoice it gives Dahab's commission and VAT back to the seller in one
// balanced entry, in part or in full, never above what is left.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Invoices::paid($this);
    $this->invoice = Invoices::of($this->order, 'seller');
    $this->seller = Customer::query()->findOrFail($this->order->seller_id);
});

it('credits a seller invoice in full: one balanced entry back to the seller', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $finance = Finance::staff($this, SeedRole::FINANCE);
    $before = Finance::available($this->seller);
    $commission = Orders::internal(AccountKind::DAHAB_COMMISSION);
    $vat = Orders::internal(AccountKind::VAT_PAYABLE);

    $res = Invoices::credit($this, $this->invoice, '684', 'The whole commission was charged by mistake.')->assertCreated()
        ->assertJsonPath('data.invoice_number', $this->invoice->invoice_no)
        ->assertJsonPath('data.net', '600.0000')
        ->assertJsonPath('data.vat', '84.0000')
        ->assertJsonPath('data.gross', '684.0000')
        ->assertJsonPath('data.issued_by.id', $finance->staff_id);

    expect($res->json('data.number'))->toMatch('/^CN-\d{4}-\d{6}$/')
        ->and(Finance::available($this->seller))->toBe(bcadd($before, '684', 4))
        ->and(Orders::internal(AccountKind::DAHAB_COMMISSION))->toBe(bcsub($commission, '600', 4))
        ->and(Orders::internal(AccountKind::VAT_PAYABLE))->toBe(bcsub($vat, '84', 4))
        ->and(Finance::globalSum())->toBe('0.0000')
        ->and(collect(Orders::lines($this->order, 'credit_note'))->pluck('kind')->sort()->values()->all())
        ->toBe(['cust_available', 'dahab_commission', 'vat_payable']);

    $invoice = Invoices::of($this->order, 'seller');
    expect((string) $invoice->gross_amount)->toBe('684.0000')
        ->and($invoice->remaining())->toBe('0.0000')
        ->and($invoice->status()->value)->toBe('credited');

    $audit = AuditLog::query()->where('action', 'credit_note.issued')->sole();
    expect($audit->actor_staff_id)->toBe($finance->staff_id)
        ->and($audit->reason)->toBe('The whole commission was charged by mistake.');

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id
        && $job->notification->event === WalletEvent::CREDIT_NOTE_ISSUED && $job->notification->amount === '684.0000'
        && $job->notification->reference === $this->invoice->invoice_no);
});

it('credits in part, splitting the VAT at the invoice rate, then refuses more than is left', function () {
    Finance::staff($this, SeedRole::FINANCE);

    Invoices::credit($this, $this->invoice, '300')->assertCreated()
        ->assertJsonPath('data.vat', '36.8421')
        ->assertJsonPath('data.net', '263.1579');

    expect(Invoices::of($this->order, 'seller')->status()->value)->toBe('partly_credited');

    Invoices::credit($this, $this->invoice, '384.01')->assertStatus(422)
        ->assertJsonPath('code', 'credit_exceeds_invoice')
        ->assertJsonPath('details.remaining', '384.0000');

    Invoices::credit($this, $this->invoice, '384')->assertCreated();
    expect(Invoices::of($this->order, 'seller')->status()->value)->toBe('credited')
        ->and(CreditNote::query()->count())->toBe(2)
        ->and(Finance::globalSum())->toBe('0.0000');
});

it('refuses a buyer invoice', function () {
    Finance::staff($this, SeedRole::FINANCE);

    Invoices::credit($this, Invoices::of($this->order, 'buyer'), '100')->assertStatus(409)
        ->assertJsonPath('code', 'invoice_not_creditable');
    expect(CreditNote::query()->count())->toBe(0);
});

it('validates the amount and the reason', function (array $body) {
    Finance::staff($this, SeedRole::FINANCE);

    $this->postJson(Invoices::STAFF_URL."/{$this->invoice->invoice_id}/credit-notes", $body, Finance::key())
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with([
    'zero' => [['amount' => '0', 'reason' => 'A long enough reason.']],
    'negative' => [['amount' => '-5', 'reason' => 'A long enough reason.']],
    'three decimals' => [['amount' => '10.123', 'reason' => 'A long enough reason.']],
    'short reason' => [['amount' => '10', 'reason' => 'Too short']],
    'long reason' => [['amount' => '10', 'reason' => str_repeat('x', 1001)]],
    'missing' => [[]],
]);

it('replays an idempotent request once and needs the key', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $key = (string) Str::uuid();

    Invoices::credit($this, $this->invoice, '100', key: $key)->assertCreated();
    Invoices::credit($this, $this->invoice, '100', key: $key)->assertCreated();
    $this->postJson(Invoices::STAFF_URL."/{$this->invoice->invoice_id}/credit-notes", ['amount' => '100', 'reason' => 'Without a key it must fail.'])
        ->assertStatus(400);

    expect(CreditNote::query()->count())->toBe(1);
});

it('shows the credit note in the seller\'s wallet history, linked to the invoice', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Invoices::credit($this, $this->invoice, '100')->assertCreated();

    $rows = Listings::as($this, $this->seller)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->json('data');
    $row = collect($rows)->firstWhere('kind', 'credit_note');
    $settled = collect($rows)->firstWhere('kind', 'balance_payment');

    expect($row['available_change'])->toBe('100.0000')
        ->and($row['invoice_id'])->toBe($this->invoice->invoice_id)
        ->and($settled['invoice_id'])->toBe($this->invoice->invoice_id);
});

it('credits a suspended seller too', function () {
    Finance::staff($this, SeedRole::FINANCE);
    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);

    Invoices::credit($this, $this->invoice, '50')->assertCreated();
});

it('needs invoice.correct to issue, invoice.view alone is not enough', function () {
    $staff = Finance::staff($this, SeedRole::OPERATIONS);
    $staff->givePermissionTo('invoice.view');

    $this->getJson(Invoices::STAFF_URL."/{$this->invoice->invoice_id}")->assertOk()->assertJsonPath('data.creditable', true);
    Invoices::credit($this, $this->invoice, '50')->assertForbidden();
});
