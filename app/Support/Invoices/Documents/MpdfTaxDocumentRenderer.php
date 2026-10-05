<?php

namespace App\Support\Invoices\Documents;

use App\Models\CreditNote;
use App\Models\TaxInvoice;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

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
        $tmp = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tmp);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmp,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('Dahab');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
