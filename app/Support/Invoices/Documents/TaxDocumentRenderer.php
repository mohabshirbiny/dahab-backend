<?php

namespace App\Support\Invoices\Documents;

use App\Models\CreditNote;
use App\Models\TaxInvoice;

/**
 * Turns a tax invoice or credit note into its one bilingual (English and
 * Arabic) PDF (spec 016 FR-023). Only stored figures are used — never a
 * recomputation. Bound to MpdfTaxDocumentRenderer; tests may bind a fake.
 */
interface TaxDocumentRenderer
{
    /** @return string the PDF bytes */
    public function renderInvoice(TaxInvoice $invoice): string;

    /** @return string the PDF bytes */
    public function renderCreditNote(CreditNote $note, TaxInvoice $invoice): string;
}
