<?php

namespace App\Support\Wallet;

use App\Support\Documents\MpdfDocument;

/**
 * The customer's wallet statement as a bilingual PDF (wallet statement PDF, item 10), from the
 * figures of BuildCustomerStatementAction; the layout is resources/views/documents/wallet-statement.
 * A class (not a closure) so tests can swap it for one that records the figures.
 */
class StatementPdfRenderer
{
    /** @param  array<string, mixed>  $statement */
    public function render(array $statement, ?array $issuer): string
    {
        $html = view('documents.wallet-statement', ['s' => $statement, 'issuer' => $issuer])->render();

        return MpdfDocument::render($html, 'Dahab wallet statement '.$statement['from'].' – '.$statement['to']);
    }
}
