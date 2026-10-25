<?php

namespace App\Support\Invoices\Documents;

use App\Models\CreditNote;
use App\Models\ListingMedia;
use App\Models\TaxInvoice;
use App\Services\IdentityDocumentStorage;
use App\Support\Documents\MpdfDocument;
use Throwable;

/**
 * The bilingual PDF through mPDF (spec 016 research R9): Arabic is shaped and
 * set right to left by mPDF's script detection, with a bundled Arabic-capable
 * font; the layout lives in resources/views/documents.
 */
final class MpdfTaxDocumentRenderer implements TaxDocumentRenderer
{
    public function renderInvoice(TaxInvoice $invoice): string
    {
        return $this->pdf(view('documents.invoice', ['invoice' => $invoice, 'photo' => $this->photo($invoice)])->render(), $invoice->invoice_no);
    }

    /** The piece's photo as a data URI, or null when the invoice has none or the file cannot be read. */
    private function photo(TaxInvoice $invoice): ?string
    {
        $id = $invoice->lines['photo_media_id'] ?? null;
        if ($id === null) {
            return null;
        }

        try {
            $media = ListingMedia::query()->whereKey($id)->first();
            if ($media === null || ! str_starts_with($media->mime, 'image/')) {
                return null;
            }

            return 'data:'.$media->mime.';base64,'.base64_encode(app(IdentityDocumentStorage::class)->read($media->storage_ref));
        } catch (Throwable) {
            return null;
        }
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
