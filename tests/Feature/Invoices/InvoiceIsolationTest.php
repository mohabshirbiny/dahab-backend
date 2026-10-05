<?php

use App\Enums\SeedRole;
use App\Models\CreditNote;
use App\Models\TaxInvoice;
use App\Support\DatabaseActor;
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

// Spec 016 FR-015, research R8, analysis U1: the engine keeps each customer's
// invoices and credit notes to themselves; only the buyer's own payment (the
// `order` scope) writes invoices, and it never reads the seller's back.

function inv016As(string $scope, string $customerId, Closure $work): mixed
{
    DatabaseActor::push($scope, customerId: $customerId);
    try {
        return $work();
    } finally {
        DatabaseActor::pop();
    }
}

function inv016Refused(Closure $work): bool
{
    DB::beginTransaction();
    try {
        $work();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return false;
    } catch (QueryException) {
        return true;
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
    $this->other = Invoices::paid($this);
    Finance::staff($this, SeedRole::FINANCE);
    Invoices::credit($this, Invoices::of($this->order, 'seller'), '100')->assertCreated();
    Invoices::credit($this, Invoices::of($this->other, 'seller'), '100')->assertCreated();
});

it('shows a customer only their own invoices and credit notes', function () {
    [$seller, $buyer] = [$this->order->seller_id, $this->order->buyer_id];

    expect(inv016As('customer', $seller, fn () => TaxInvoice::query()->pluck('invoice_no')->all()))->toBe([$this->order->order_ref.'-S'])
        ->and(inv016As('customer', $buyer, fn () => TaxInvoice::query()->pluck('invoice_no')->all()))->toBe([$this->order->order_ref.'-B'])
        ->and(inv016As('customer', $seller, fn () => CreditNote::query()->count()))->toBe(1)
        ->and(inv016As('customer', $buyer, fn () => CreditNote::query()->count()))->toBe(0)
        ->and(inv016As('order', $buyer, fn () => TaxInvoice::query()->pluck('party_role')->map->value->all()))->toBe(['buyer']);
});

it('lets no customer write an invoice or a credit note outside their own payment', function () {
    $unpaid = Orders::accepted($this);
    $row = fn (string $party, string $customerId) => [
        'invoice_id' => (string) Str::uuid(), 'invoice_no' => $unpaid->order_ref.($party === 'seller' ? '-S' : '-B'),
        'order_id' => $unpaid->order_id, 'party_role' => $party, 'customer_id' => $customerId,
        'net_amount' => '1', 'vat_amount' => '0', 'gross_amount' => '1', 'vat_rate' => '0', 'lines' => '{}',
    ];

    // Customer scope (not the order scope): refused even for the buyer.
    expect(inv016Refused(fn () => inv016As('customer', $unpaid->buyer_id, fn () => DB::table('tax_invoice')->insert($row('buyer', $unpaid->buyer_id)))))->toBeTrue()
        // The seller's order scope cannot issue on an order they did not pay for.
        ->and(inv016Refused(fn () => inv016As('order', $unpaid->seller_id, fn () => DB::table('tax_invoice')->insert($row('seller', $unpaid->seller_id)))))->toBeTrue()
        // A credit note is staff-only.
        ->and(inv016Refused(fn () => inv016As('order', $this->order->seller_id, fn () => DB::table('credit_note')->insert([
            'credit_note_id' => (string) Str::uuid(), 'credit_note_no' => 'CN-X', 'invoice_id' => Invoices::of($this->order, 'seller')->invoice_id,
            'customer_id' => $this->order->seller_id, 'net_amount' => '1', 'vat_amount' => '0', 'gross_amount' => '1',
            'reason' => 'A customer trying to credit.', 'issued_by' => Finance::staff($this, SeedRole::FINANCE)->staff_id,
            'ledger_txn_id' => (string) Str::uuid(),
        ]))))->toBeTrue();
});

it('lets the buyer\'s payment write the seller\'s invoice it can never read back', function () {
    $order = Orders::inspected($this, Orders::accepted($this), '10.000');
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    expect(TaxInvoice::query()->where('order_id', $order->order_id)->count())->toBe(2)
        ->and(inv016As('order', $order->buyer_id, fn () => TaxInvoice::query()->where('order_id', $order->order_id)->count()))->toBe(1)
        ->and(inv016As('customer', $order->buyer_id, fn () => TaxInvoice::query()->where('party_role', 'seller')->where('order_id', $order->order_id)->exists()))->toBeFalse();
});
