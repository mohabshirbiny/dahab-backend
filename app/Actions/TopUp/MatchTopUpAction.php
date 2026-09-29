<?php

namespace App\Actions\TopUp;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Actions\TopUp\Concerns\CreditsWallet;
use App\Enums\AuditEvent;
use App\Enums\TopUpStatus;
use App\Exceptions\DomainApiException;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\RequestContext;
use App\Support\TopUpMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff saw the money arrive and credit the notice (spec 009 US2, FR-016,
 * FR-017, FR-020; Part 2 §9 "Match an incoming transfer"). One transaction:
 * lock the notice → check the move → post the `topup` entry through the
 * money service → mark it credited with the (unique) ledger id → audit. The
 * customer's message goes after commit.
 *
 * The credited amount is what arrived; a note is required when it differs
 * from the claim. A suspended customer needs the provider's transaction
 * reference (M1). A customer rejected on re-review after submitting is
 * still credited, reference optional (L4): their money is theirs.
 */
final class MatchTopUpAction
{
    use CreditsWallet;

    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $topUpId, string $amount, int $receivingAccountId, ?string $note, ?string $arrivalReference, ?RequestContext $ctx = null): TopUp
    {
        $topUp = DB::transaction(function () use ($actor, $topUpId, $amount, $receivingAccountId, $note, $arrivalReference, $ctx) {
            $topUp = TopUp::query()->whereKey($topUpId)->lockForUpdate()->firstOrFail();

            if (! $topUp->status->canMoveTo(TopUpStatus::CREDITED)) {
                throw DomainApiException::illegalTopUpTransition();
            }

            $account = ReceivingAccount::query()->whereKey($receivingAccountId)->first();
            if ($account === null || $account->method !== $topUp->method) {
                throw ValidationException::withMessages(['receiving_account_id' => ['Pick the account the money reached; it must be a '.$topUp->method->label().' account.']]);
            }

            $amount = bcadd($amount, '0', 2);
            $note = filled($note) ? trim((string) $note) : null;
            if (bccomp($amount, (string) $topUp->claimed_amount, 2) !== 0 && $note === null) {
                throw ValidationException::withMessages(['note' => ['The amount differs from what the customer said they sent: say why.']]);
            }

            $arrivalReference = filled($arrivalReference) ? trim((string) $arrivalReference) : null;
            $customerStatus = $this->statusForCredit($topUp->customer_id, $arrivalReference);
            $before = $topUp->status;

            $txn = $this->postCredit($this->post, $actor, $topUp->customer_id, $amount,
                "Top-up {$topUp->number()} · {$topUp->method->label()} · matched from notice {$topUp->reference}");

            $topUp->forceFill([
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
                AuditEvent::TOPUP_MATCHED,
                'success',
                [
                    'status' => TopUpStatus::CREDITED->value,
                    'number' => $topUp->number(),
                    'customer_ref' => $topUp->customer()->value('display_ref'),
                    'claimed_amount' => TopUpMoney::format($topUp->claimed_amount),
                    'credited_amount' => TopUpMoney::format($amount),
                    'receiving_account_id' => $account->receiving_account_id,
                    'ledger_txn_id' => $txn->ledger_txn_id,
                    'customer_status' => $customerStatus->value,
                    'arrival_reference' => $arrivalReference,
                ],
                'topup',
                $topUp->topup_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: ['status' => $before->value],
                reason: $note,
            );

            $this->notifyCreditedAfterCommit($topUp);

            return $topUp;
        });

        return $topUp->refresh();
    }
}
