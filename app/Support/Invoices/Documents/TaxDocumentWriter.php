<?php

namespace App\Support\Invoices\Documents;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\TaxInvoice;
use App\Support\Invoices\IssuerDetails;
use Illuminate\Support\Facades\DB;

/**
 * Generate and store one tax document (spec 016 FR-023a, research R5, R10).
 * Runs after the money transaction committed, in the system scope (the job or
 * the five-minute sweep). Under a row lock it copies, once, Dahab's details
 * (when the configuration is complete) and — for an invoice — the party's
 * name and reference, renders the bilingual PDF and records it. A document
 * already stored is never made again; an incomplete configuration leaves it
 * waiting.
 */
final class TaxDocumentWriter
{
    public function __construct(
        private readonly TaxDocumentRenderer $renderer,
        private readonly TaxDocumentStore $store,
        private readonly IssuerDetails $issuer,
    ) {}

    /** @return bool whether the document is stored now */
    public function invoice(string $invoiceId): bool
    {
        return DB::transaction(function () use ($invoiceId) {
            $invoice = TaxInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            if ($invoice === null) {
                return false;
            }
            if ($invoice->storage_ref !== null) {
                return true;
            }

            $issuer = $invoice->issuer ?? $this->issuer->snapshot();
            if ($issuer === null) {
                return false;
            }
            $party = $invoice->party ?? $this->party($invoice->customer_id);

            $invoice->issuer = $issuer;
            $invoice->party = $party;
            $ref = $this->store->put($invoice->customer_id, $invoice->invoice_no, $this->renderer->renderInvoice($invoice));

            TaxInvoice::query()->whereKey($invoiceId)->whereNull('storage_ref')->update([
                'issuer' => json_encode($issuer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'party' => json_encode($party, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'storage_ref' => $ref,
                'document_at' => now(),
            ]);

            return true;
        });
    }

    /** @return bool whether the document is stored now */
    public function creditNote(string $creditNoteId): bool
    {
        return DB::transaction(function () use ($creditNoteId) {
            $note = CreditNote::query()->whereKey($creditNoteId)->lockForUpdate()->first();
            if ($note === null) {
                return false;
            }
            if ($note->storage_ref !== null) {
                return true;
            }

            $issuer = $note->issuer ?? $this->issuer->snapshot();
            if ($issuer === null) {
                return false;
            }

            /** @var TaxInvoice $invoice */
            $invoice = TaxInvoice::query()->whereKey($note->invoice_id)->firstOrFail();
            if ($invoice->party === null) {
                $invoice->party = $this->party($invoice->customer_id);
            }

            $note->issuer = $issuer;
            $ref = $this->store->put($note->customer_id, $note->credit_note_no, $this->renderer->renderCreditNote($note, $invoice));

            CreditNote::query()->whereKey($creditNoteId)->whereNull('storage_ref')->update([
                'issuer' => json_encode($issuer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'storage_ref' => $ref,
                'document_at' => now(),
            ]);

            return true;
        });
    }

    /** @return array{full_name: string|null, display_ref: string} */
    private function party(string $customerId): array
    {
        $customer = Customer::query()->whereKey($customerId)->firstOrFail();

        return ['full_name' => $customer->full_name, 'display_ref' => (string) $customer->display_ref];
    }
}
