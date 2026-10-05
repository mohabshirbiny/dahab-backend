<?php

namespace App\Actions\Invoices;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\WalletEvent;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Jobs\RenderTaxDocumentJob;
use App\Models\Account;
use App\Models\CreditNote;
use App\Models\Staff;
use App\Models\TaxInvoice;
use App\Notifications\WalletNotification;
use App\Support\DatabaseActor;
use App\Support\Invoices\CreditNoteSplit;
use App\Support\Invoices\IssuerDetails;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issue or correct a tax invoice — a credit note (spec 016 US4, FR-018–FR-020;
 * Part 1 §4.2, `invoice.correct`). The invoice never changes: under its row
 * lock a seller invoice is credited in part or in full, never above what is
 * left; one balanced `credit_note` entry gives the commission and VAT back to
 * the seller (`dahab_commission −net`, `vat_payable −vat`, available
 * `+gross`). Audited with the reason; the seller is told and the PDF is made
 * after commit. The database refuses the same (DH012) if this check is ever
 * bypassed.
 */
final class IssueCreditNoteAction
{
    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly RecordAuditLogAction $audit,
        private readonly IssuerDetails $issuer,
    ) {}

    public function handle(Staff $staff, string $invoiceId, string $amount, string $reason, ?RequestContext $ctx = null): CreditNote
    {
        return DB::transaction(function () use ($staff, $invoiceId, $amount, $reason, $ctx) {
            /** @var TaxInvoice $invoice */
            $invoice = TaxInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if (! $invoice->isCreditable()) {
                throw DomainApiException::invoiceNotCreditable();
            }

            $remaining = $invoice->remaining();
            if (Money::cmp($amount, $remaining) > 0) {
                throw DomainApiException::creditExceedsInvoice($remaining);
            }

            $split = CreditNoteSplit::of($amount, (string) $invoice->vat_rate);
            $number = $this->nextNumber();

            $lines = DatabaseActor::ledger(function () use ($invoice, $split) {
                $lines = [
                    new LedgerLine(Account::internal(AccountKind::DAHAB_COMMISSION), '-'.$split['net']),
                    new LedgerLine(Account::forCustomerKind($invoice->customer_id, AccountKind::CUST_AVAILABLE), $split['gross']),
                ];
                if (Money::cmp($split['vat'], '0') > 0) {
                    $lines[] = new LedgerLine(Account::internal(AccountKind::VAT_PAYABLE), '-'.$split['vat']);
                }

                return $lines;
            });

            $txn = $this->post->handle(new LedgerEntry(
                LedgerEventKind::CREDIT_NOTE,
                $lines,
                actorStaffId: $staff->staff_id,
                memo: "Credit note {$number} on {$invoice->invoice_no}",
                orderId: $invoice->order_id,
            ));

            $issuer = $this->issuer->snapshot();
            $note = CreditNote::query()->create([
                'credit_note_no' => $number,
                'invoice_id' => $invoice->invoice_id,
                'customer_id' => $invoice->customer_id,
                'net_amount' => $split['net'],
                'vat_amount' => $split['vat'],
                'gross_amount' => $split['gross'],
                'reason' => $reason,
                'issued_by' => $staff->staff_id,
                'ledger_txn_id' => $txn->ledger_txn_id,
                'issuer' => $issuer,
                'issued_at' => CarbonImmutable::now(),
            ]);

            $this->audit->execute(AuditEvent::CREDIT_NOTE_ISSUED, 'success',
                ['credit_note_no' => $number, 'invoice_no' => $invoice->invoice_no, 'customer_id' => $invoice->customer_id,
                    'net' => $split['net'], 'vat' => $split['vat'], 'gross' => $split['gross'],
                    'remaining' => Money::fixed4(Money::sub($remaining, $split['gross'])), 'ledger_txn_id' => $txn->ledger_txn_id],
                'credit_note', $note->credit_note_id, $ctx, actorStaffId: $staff->staff_id, reason: $reason);

            $customerId = $invoice->customer_id;
            DB::afterCommit(function () use ($customerId, $split, $invoice, $note) {
                NotifyCustomerJob::dispatch($customerId,
                    new WalletNotification(WalletEvent::CREDIT_NOTE_ISSUED, $split['gross'], reference: $invoice->invoice_no));
                RenderTaxDocumentJob::dispatch(RenderTaxDocumentJob::CREDIT_NOTE, $note->credit_note_id);
            });

            return $note;
        });
    }

    /** `CN-YYYY-NNNNNN`: the Cairo year and a number that never resets (spec 016 research R4). */
    private function nextNumber(): string
    {
        $n = (int) DB::selectOne("SELECT nextval('credit_note_no_seq') AS n")->n;

        return sprintf('CN-%s-%06d', CarbonImmutable::now('Africa/Cairo')->format('Y'), $n);
    }
}
