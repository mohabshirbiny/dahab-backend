<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\TaxInvoice;
use App\Support\Finance\Csv;
use App\Support\Invoices\InvoiceListQuery;

/** The Invoices list as CSV, same filters, capped, audited (spec 016 FR-013). */
final class ExportInvoicesAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function handle(Staff $viewer, InvoiceListQuery $query): array
    {
        $cap = (int) config('dahab-invoices.export_cap');
        $csv = new Csv(['Number', 'Date', 'Order', 'Party', 'Customer ref', 'Customer name', 'Net', 'VAT', 'Gross', 'Credited', 'Status'], $cap);

        $rows = $query->apply(TaxInvoice::query()->with('customer'))
            ->orderByDesc('issued_at')->orderByDesc('invoice_id')->limit($cap + 1)->get();
        ListInvoicesAction::withCredited($rows);

        $rows->each(fn (TaxInvoice $i) => $csv->row([
            $i->invoice_no, $i->issued_at->setTimezone('Africa/Cairo')->toIso8601String(), substr($i->invoice_no, 0, -2),
            $i->party_role->value, $i->customer?->display_ref, $i->customer?->full_name,
            (string) $i->net_amount, (string) $i->vat_amount, (string) $i->gross_amount, $i->credited(), $i->status()->value,
        ]));

        $result = $csv->finish();

        $this->audit->execute(AuditEvent::INVOICES_EXPORTED, 'success',
            $query->toArray() + ['rows' => $result['rows'], 'truncated' => $result['truncated']],
            entityType: 'tax_invoice', actorStaffId: $viewer->staff_id);

        return $result;
    }
}
