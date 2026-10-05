<?php

namespace Tests\Support;

use App\Models\CreditNote;
use App\Models\TaxInvoice;
use App\Support\Invoices\Documents\TaxDocumentRenderer;
use RuntimeException;

/** A fast stand-in for the mPDF renderer (spec 016 tests). Can be told to fail. */
final class FakeTaxDocumentRenderer implements TaxDocumentRenderer
{
    /** @var list<string> */
    public array $rendered = [];

    public bool $fail = false;

    public function renderInvoice(TaxInvoice $invoice): string
    {
        return $this->render($invoice->invoice_no.'|'.($invoice->party['full_name'] ?? '').'|'.($invoice->issuer['legal_name_en'] ?? ''));
    }

    public function renderCreditNote(CreditNote $note, TaxInvoice $invoice): string
    {
        return $this->render($note->credit_note_no.'|'.$invoice->invoice_no);
    }

    private function render(string $what): string
    {
        if ($this->fail) {
            throw new RuntimeException('renderer down');
        }
        $this->rendered[] = $what;

        return '%PDF-fake '.$what;
    }
}
