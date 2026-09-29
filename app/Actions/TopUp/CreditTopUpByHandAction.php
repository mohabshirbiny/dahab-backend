<?php

namespace App\Actions\TopUp;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\TopUp\Concerns\CreditsWallet;
use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\TopUpOrigin;
use App\Enums\TopUpStatus;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\RequestContext;
use App\Support\TopUpMoney;
use App\Support\TopUpReference;
use Illuminate\Support\Facades\DB;

/**
 * Money arrived with no notice (spec 009 US3, FR-018): staff record it as a
 * credited transfer and credit the wallet in one transaction, with the same
 * ledger, audit and after-commit message as a match.
 *
 * Who may be credited (post-analysis clarification):
 *  - verified and active — yes;
 *  - verified and suspended (suspended from active) — only with the
 *    provider's transaction reference: staff-side reconciliation of money
 *    that already arrived. The customer side stays closed (FR-018a);
 *  - awaiting verification or rejected, including suspended from those
 *    states — refused (verification_required), nothing written.
 */
final class CreditTopUpByHandAction
{
    use CreditsWallet;

    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $customerId, string $amount, int $receivingAccountId, string $note, ?string $arrivalReference, ?RequestContext $ctx = null): TopUp
    {
        $topUp = DB::transaction(function () use ($actor, $customerId, $amount, $receivingAccountId, $note, $arrivalReference, $ctx) {
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

            $status = $customer->status instanceof CustomerStatus ? $customer->status : CustomerStatus::from((string) $customer->status);
            $verified = $status === CustomerStatus::ACTIVE
                || ($status === CustomerStatus::SUSPENDED && $customer->status_before_suspension === CustomerStatus::ACTIVE);
            if (! $verified) {
                throw DomainApiException::verificationRequired();
            }

            $arrivalReference = filled($arrivalReference) ? trim((string) $arrivalReference) : null;
            $status = $this->statusForCredit($customer->customer_id, $arrivalReference);

            $account = ReceivingAccount::query()->findOrFail($receivingAccountId);
            $amount = bcadd($amount, '0', 2);
            $note = trim($note);

            // The row needs its ledger id (topup_credited_shape), so it is
            // inserted after the entry; the entry's memo names its number.
            $topUpNo = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('topup', 'topup_no')) AS n")->n;
            $txn = $this->postCredit($this->post, $actor, $customer->customer_id, $amount,
                "Top-up TOP-{$topUpNo} · {$account->method->label()} · credited by hand");

            $topUp = new TopUp;
            $topUp->forceFill([
                'topup_no' => $topUpNo,
                'customer_id' => $customer->customer_id,
                'origin' => TopUpOrigin::BY_HAND,
                'method' => $account->method,
                'reference' => TopUpReference::for($customer),
                'status' => TopUpStatus::CREDITED,
                'credited_amount' => $amount,
                'receiving_account_id' => $account->receiving_account_id,
                'credit_note' => $note,
                'arrival_reference' => $arrivalReference,
                'credited_by' => $actor->staff_id,
                'credited_at' => now(),
                'ledger_txn_id' => $txn->ledger_txn_id,
            ])->save();

            $this->audit->execute(
                AuditEvent::TOPUP_CREDITED_BY_HAND,
                'success',
                [
                    'status' => TopUpStatus::CREDITED->value,
                    'number' => $topUp->number(),
                    'customer_ref' => $customer->display_ref,
                    'customer_id' => $customer->customer_id,
                    'customer_status' => $status->value,
                    'method' => $account->method->value,
                    'credited_amount' => TopUpMoney::format($amount),
                    'receiving_account_id' => $account->receiving_account_id,
                    'arrival_reference' => $arrivalReference,
                    'ledger_txn_id' => $txn->ledger_txn_id,
                ],
                'topup',
                $topUp->topup_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                reason: $note,
            );

            $this->notifyCreditedAfterCommit($topUp);

            return $topUp;
        });

        return $topUp->refresh();
    }
}
