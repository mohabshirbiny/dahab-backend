<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\CreditNote;
use App\Models\Staff;
use App\Models\TaxInvoice;
use App\Support\Invoices\Documents\TaxDocumentStore;

/**
 * Open a tax invoice's or credit note's PDF (spec 016 FR-012, FR-015,
 * FR-024). A staff view is audited; a customer reading their own is not
 * (Clarifications). Row-level security already hides another customer's
 * documents; the caller passes a row it could load. Before the document is
 * generated the answer is `document_not_ready`.
 */
final class ViewTaxDocumentAction
{
    public function __construct(
        private readonly TaxDocumentStore $store,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{bytes: string, filename: string} */
    public function handle(TaxInvoice|CreditNote $document, ?Staff $viewer = null): array
    {
        if ($document->storage_ref === null) {
            throw DomainApiException::documentNotReady();
        }

        $bytes = $this->store->read($document->storage_ref);
        $invoice = $document instanceof TaxInvoice;
        $number = $invoice ? $document->invoice_no : $document->credit_note_no;

        if ($viewer !== null) {
            $this->audit->execute($invoice ? AuditEvent::INVOICE_DOCUMENT_VIEWED : AuditEvent::CREDIT_NOTE_DOCUMENT_VIEWED, 'success',
                ['number' => $number, 'customer_id' => $document->customer_id],
                $invoice ? 'tax_invoice' : 'credit_note', $document->getKey(), actorStaffId: $viewer->staff_id);
        }

        return ['bytes' => $bytes, 'filename' => $number.'.pdf'];
    }
}
