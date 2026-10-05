<?php

use App\Enums\SeedRole;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Invoices;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 016 plan (performance): the Invoices list with its figures and the
// customer's list stay under 300 ms at p95 with 10,000 invoices; the export
// of 10,000 rows takes under 10 s. Rows are written in bulk against one real
// paid order: inside this test's transaction (rolled back afterwards) the
// one-per-party constraint and the reconciliation trigger are set aside.

function inv016P95(int $runs, Closure $call): float
{
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $call();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    return $times[(int) ceil(0.95 * $runs) - 1];
}

it('answers within 300 ms at p95 with 10,000 invoices, and exports them', function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $order = Invoices::paid($this);

    DatabaseActor::elevate('maintenance', function () use ($order) {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE tax_invoice DROP CONSTRAINT tax_invoice_one_per_party');
        DB::statement('ALTER TABLE tax_invoice DISABLE TRIGGER trg_tax_invoice_reconciled');
        DB::statement("
            INSERT INTO tax_invoice (invoice_no, order_id, party_role, customer_id, net_amount, vat_amount, gross_amount, vat_rate, lines, issued_at)
            SELECT 'DH-2026-P' || lpad(g::text, 6, '0') || '-S', ?::uuid, 'seller', ?::uuid, 600, 84, 684, 14, '{}'::jsonb,
                   now() - (g || ' minutes')::interval
              FROM generate_series(1, 9998) g
        ", [$order->order_id, $order->seller_id]);
        DB::statement('ANALYZE tax_invoice');
        DB::statement('ANALYZE credit_note');
    });

    Finance::staff($this, SeedRole::FINANCE);
    $this->getJson(Invoices::STAFF_URL)->assertOk();

    expect(inv016P95(10, fn () => $this->getJson(Invoices::STAFF_URL)->assertOk()))->toBeLessThan(300.0)
        ->and(inv016P95(10, fn () => $this->getJson(Invoices::STAFF_URL.'?q=P0099&status=issued')->assertOk()))->toBeLessThan(300.0);

    $t = hrtime(true);
    $csv = (string) $this->get(Invoices::STAFF_URL.'/export', ['Accept' => 'application/json'])->assertOk()->getContent();
    expect((hrtime(true) - $t) / 1e9)->toBeLessThan(10.0)
        ->and(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(10000);

    $seller = Orders::seller($order);
    expect(inv016P95(10, fn () => Listings::as($this, $seller)->getJson(Invoices::CUSTOMER_URL)->assertOk()))->toBeLessThan(300.0);
});
