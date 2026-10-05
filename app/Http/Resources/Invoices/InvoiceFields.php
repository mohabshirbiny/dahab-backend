<?php

namespace App\Http\Resources\Invoices;

use App\Models\CreditNote;
use App\Models\TaxInvoice;

/**
 * The fields staff and customers share for a tax invoice and a credit note
 * (spec 016 contracts). Amounts are 4-dp strings. No Tax Authority status
 * exists: `eta_reference` is never exposed (FR-022).
 */
final class InvoiceFields
{
    /** @return array<string, mixed> */
    public static function summary(TaxInvoice $i): array
    {
        return [
            'id' => $i->invoice_id,
            'number' => $i->invoice_no,
            'party' => $i->party_role->value,
            'order_id' => $i->order_id,
            'order_ref' => substr($i->invoice_no, 0, -2),
            'issued_at' => $i->issued_at->toIso8601String(),
            'net' => bcadd((string) $i->net_amount, '0', 4),
            'vat' => bcadd((string) $i->vat_amount, '0', 4),
            'gross' => bcadd((string) $i->gross_amount, '0', 4),
            'credited' => $i->credited(),
            'remaining' => $i->remaining(),
            'status' => $i->status()->value,
            'document_ready' => $i->documentReady(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(TaxInvoice $i): array
    {
        return [
            'vat_rate' => (string) $i->vat_rate,
            'lines' => $i->lines,
            'issuer' => $i->issuer,
            'creditable' => $i->isCreditable() && bccomp($i->remaining(), '0', 4) > 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function creditNote(CreditNote $n, ?string $invoiceNumber = null): array
    {
        return [
            'id' => $n->credit_note_id,
            'number' => $n->credit_note_no,
            'invoice_id' => $n->invoice_id,
            'invoice_number' => $invoiceNumber ?? $n->invoice?->invoice_no,
            'reason' => $n->reason,
            'net' => bcadd((string) $n->net_amount, '0', 4),
            'vat' => bcadd((string) $n->vat_amount, '0', 4),
            'gross' => bcadd((string) $n->gross_amount, '0', 4),
            'issued_at' => $n->issued_at->toIso8601String(),
            'document_ready' => $n->documentReady(),
        ];
    }
}
