<?php

namespace App\Support\Invoices\Documents;

use App\Models\CreditNote;
use App\Models\TaxInvoice;
use App\Support\Documents\MpdfDocument;

/**
 * The bilingual PDF through mPDF (spec 016 research R9): Arabic is shaped and
 * set right to left by mPDF's script detection, with a bundled Arabic-capable
 * font; the layout lives in resources/views/documents.
 */
final class MpdfTaxDocumentRenderer implements TaxDocumentRenderer
{
    public function renderInvoice(TaxInvoice $invoice): string
    {
        return $this->pdf(view('documents.invoice', ['invoice' => $invoice])->render(), $invoice->invoice_no);
    }

    public function renderCreditNote(CreditNote $note, TaxInvoice $invoice): string
    {
        return $this->pdf(view('documents.credit-note', ['note' => $note, 'invoice' => $invoice])->render(), $note->credit_note_no);
    }

    private function pdf(string $html, string $title): string
    {
        return MpdfDocument::render($html, $title);
    }
}
