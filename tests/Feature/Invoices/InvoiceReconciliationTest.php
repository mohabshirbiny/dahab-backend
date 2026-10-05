<?php

use App\Enums\SeedRole;
use App\Models\CreditNote;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 SC-002: the invoices and credit notes reconcile with the ledger to
// the piastre — seller net and VAT less credit notes equal the net movement of
// Dahab's commission and VAT payable; buyer invoices equal the buyer totals;
// the whole ledger still sums to zero; the page figures equal the same sums.

/** The net movement of an internal account across settlements and credit notes. */
function inv016Moved(string $accountKind): string
{
    return DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting as p')
        ->join('account as a', 'a.account_id', '=', 'p.account_id')
        ->join('ledger_transaction as t', 't.ledger_txn_id', '=', 'p.ledger_txn_id')
        ->where('a.kind', $accountKind)->whereIn('t.event_kind', ['balance_payment', 'credit_note'])
        ->selectRaw('COALESCE(SUM(p.amount), 0)::numeric(18,4)::text AS s')->value('s'));
}

it('reconciles every invoice and credit note with the ledger', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();

    $orders = [Invoices::paid($this), Invoices::paid($this, null, '9.900'), Invoices::paidDiamond($this), Invoices::paidGoldWithDiamond($this)];
    Finance::staff($this, SeedRole::FINANCE);
    Invoices::credit($this, Invoices::of($orders[0], 'seller'), '684')->assertCreated();
    Invoices::credit($this, Invoices::of($orders[1], 'seller'), '123.45')->assertCreated();
    Invoices::credit($this, Invoices::of($orders[2], 'seller'), '1000')->assertCreated();

    $sellerNet = (string) TaxInvoice::query()->where('party_role', 'seller')->sum('net_amount');
    $sellerVat = (string) TaxInvoice::query()->where('party_role', 'seller')->sum('vat_amount');
    $cnNet = (string) CreditNote::query()->sum('net_amount');
    $cnVat = (string) CreditNote::query()->sum('vat_amount');
    $buyerGross = (string) TaxInvoice::query()->where('party_role', 'buyer')->sum('gross_amount');
    $buyerTotals = (string) collect($orders)->reduce(fn ($c, $o) => bcadd($c, (string) $o->final_buyer_total, 4), '0');

    expect(bcsub($sellerNet, $cnNet, 4))->toBe(inv016Moved('dahab_commission'))
        ->and(bcsub($sellerVat, $cnVat, 4))->toBe(inv016Moved('vat_payable'))
        ->and(bcadd($buyerGross, '0', 4))->toBe($buyerTotals)
        ->and(Finance::globalSum())->toBe('0.0000');

    // Each credit note's split adds up and never exceeds its invoice.
    CreditNote::query()->get()->each(fn (CreditNote $n) => expect(bcadd((string) $n->net_amount, (string) $n->vat_amount, 4))->toBe((string) $n->gross_amount));
    TaxInvoice::query()->with('creditNotes')->get()->each(fn (TaxInvoice $i) => expect(bccomp($i->credited(), (string) $i->gross_amount, 4))->toBeLessThanOrEqual(0));

    $figures = $this->getJson(Invoices::STAFF_URL)->assertOk()->json('meta.figures.month');
    expect($figures['net_invoiced'])->toBe(bcadd($sellerNet, '0', 4))
        ->and($figures['vat_collected'])->toBe(bcsub($sellerVat, $cnVat, 4))
        ->and($figures['credit_notes_amount'])->toBe(bcadd((string) CreditNote::query()->sum('gross_amount'), '0', 4))
        ->and($figures['issued_count'])->toBe(8);
});
