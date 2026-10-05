<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\TaxInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 data-model: the tables, their checks and the DH012 guards —
// append-only, snapshots set once, every invoice equal to its settlement, a
// credit note only on a seller invoice and never above it, each tied to its
// exact refund entry.

/** The SQLSTATE a statement fails with, or null; any deferred check is forced now. */
function inv016State(Closure $work): ?string
{
    DB::beginTransaction();
    try {
        $work();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return null;
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    } finally {
        DB::rollBack();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Invoices::paid($this);
    $this->seller = Invoices::of($this->order, 'seller');
});

it('has both tables under forced row-level security and the new ledger kind', function () {
    $rls = collect(DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname IN ('tax_invoice','credit_note')"))
        ->keyBy('relname');

    expect($rls)->toHaveCount(2)
        ->and($rls['tax_invoice']->relforcerowsecurity)->toBeTrue()
        ->and($rls['credit_note']->relforcerowsecurity)->toBeTrue()
        ->and(collect(DB::select('SELECT unnest(enum_range(NULL::ledger_event_kind))::text AS v'))->pluck('v'))->toContain('credit_note');
});

it('never edits or deletes an issued invoice', function () {
    $id = $this->seller->invoice_id;

    expect(inv016State(fn () => DB::table('tax_invoice')->where('invoice_id', $id)->update(['net_amount' => '1'])))->toBe('DH012')
        ->and(inv016State(fn () => DB::table('tax_invoice')->where('invoice_id', $id)->update(['eta_reference' => 'X'])))->toBe('DH012')
        ->and(inv016State(fn () => DB::table('tax_invoice')->where('invoice_id', $id)->delete()))->toBe('DH012');
});

it('sets the snapshot and document columns once', function () {
    $id = $this->seller->invoice_id;
    $set = fn () => DB::table('tax_invoice')->where('invoice_id', $id)
        ->update(['storage_ref' => 'tax-documents/x.pdf.enc', 'document_at' => now(), 'party' => json_encode(['full_name' => 'A'])]);

    expect(inv016State($set))->toBeNull();
    $set();
    expect(inv016State(fn () => DB::table('tax_invoice')->where('invoice_id', $id)->update(['storage_ref' => 'other.pdf.enc'])))->toBe('DH012')
        ->and(inv016State(fn () => DB::table('tax_invoice')->where('invoice_id', $id)->update(['storage_ref' => null, 'document_at' => null])))->toBe('DH012');
});

it('enforces the shape checks and one invoice per order and party', function () {
    $base = fn (array $over) => fn () => DB::table('tax_invoice')->insert(array_merge([
        'invoice_id' => (string) Str::uuid(), 'invoice_no' => 'DH-2026-999999-S', 'order_id' => $this->order->order_id,
        'party_role' => 'seller', 'customer_id' => $this->order->seller_id, 'net_amount' => '600', 'vat_amount' => '84',
        'gross_amount' => '684', 'vat_rate' => '14', 'lines' => '{}',
    ], $over));

    expect(inv016State($base(['gross_amount' => '700'])))->toBe('23514')
        ->and(inv016State($base(['invoice_no' => 'DH-2026-999999-B'])))->toBe('23514')
        ->and(inv016State($base(['party_role' => 'buyer', 'invoice_no' => 'DH-2026-999999-B', 'customer_id' => $this->order->buyer_id])))->toBe('23514')
        ->and(inv016State($base(['storage_ref' => 'x'])))->toBe('23514')
        ->and(inv016State($base(['party_role' => 'neither'])))->toBe('23514')
        ->and(inv016State($base([])))->toBe('23505');
});

it('refuses an invoice that does not match its order\'s settlement', function () {
    $unpaid = Orders::accepted($this);
    $insert = fn (array $over) => fn () => DB::table('tax_invoice')->insert(array_merge([
        'invoice_id' => (string) Str::uuid(), 'invoice_no' => $unpaid->order_ref.'-S', 'order_id' => $unpaid->order_id,
        'party_role' => 'seller', 'customer_id' => $unpaid->seller_id, 'net_amount' => '600', 'vat_amount' => '84',
        'gross_amount' => '684', 'vat_rate' => '14', 'lines' => '{}',
    ], $over));

    // Not settled yet.
    expect(inv016State($insert([])))->toBe('DH012');
});

it('caps credit notes at the invoice and refuses a buyer invoice, in the database too', function () {
    $staff = Finance::staff($this, SeedRole::FINANCE);
    $raw = fn (string $invoiceId, string $customerId, string $gross) => fn () => DB::table('credit_note')->insert([
        'credit_note_id' => (string) Str::uuid(), 'credit_note_no' => 'CN-T-'.Str::random(6), 'invoice_id' => $invoiceId,
        'customer_id' => $customerId, 'net_amount' => $gross, 'vat_amount' => '0', 'gross_amount' => $gross,
        'reason' => 'A raw insert for the guard.', 'issued_by' => $staff->staff_id, 'ledger_txn_id' => (string) Str::uuid(),
    ]);

    $buyer = Invoices::of($this->order, 'buyer');
    expect(inv016State($raw($this->seller->invoice_id, $this->order->seller_id, '685')))->toBe('DH012')
        ->and(inv016State($raw($buyer->invoice_id, $this->order->buyer_id, '10')))->toBe('DH012');
});

it('ties every credit note to its exact refund entry', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $note = Invoices::credit($this, $this->seller, '100')->assertCreated()->json('data');
    $txn = DB::table('credit_note')->where('credit_note_id', $note['id'])->value('ledger_txn_id');
    $staff = DB::table('credit_note')->where('credit_note_id', $note['id'])->value('issued_by');

    // Another credit note naming the same amounts but a wrong entry.
    $otherTxn = DB::table('ledger_transaction')->where('event_kind', 'balance_payment')->value('ledger_txn_id');
    expect(inv016State(fn () => DB::table('credit_note')->insert([
        'credit_note_id' => (string) Str::uuid(), 'credit_note_no' => 'CN-T-000001', 'invoice_id' => $this->seller->invoice_id,
        'customer_id' => $this->order->seller_id, 'net_amount' => '87.7193', 'vat_amount' => '12.2807', 'gross_amount' => '100',
        'reason' => 'Pointing at the settlement instead.', 'issued_by' => $staff, 'ledger_txn_id' => $otherTxn,
    ])))->toBe('DH012')
        ->and($txn)->not->toBeNull()
        ->and(inv016State(fn () => DB::table('credit_note')->where('credit_note_id', $note['id'])->update(['reason' => 'Changed afterwards!'])))->toBe('DH012');
});

it('can be truncated by the test suites', function () {
    // Fire the pending deferred checks first: TRUNCATE refuses a table with pending trigger events.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('TRUNCATE credit_note, tax_invoice CASCADE');

    expect(TaxInvoice::query()->count())->toBe(0)->and(Customer::query()->count())->toBeGreaterThan(0);
});
