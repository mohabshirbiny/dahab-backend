<?php

namespace App\Actions\Finance;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Ledger\PostLedgerEntryAction;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\BankMovementKind;
use App\Enums\LedgerEventKind;
use App\Enums\UploadPurpose;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\BankMovement;
use App\Models\Staff;
use App\Services\UploadTokenStore;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use App\Support\Pricing\Money;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record a bank movement outside the app (spec 015 FR-010; Part 2 §9): one
 * `bank_movement` row and — for every kind but an own-account transfer — one
 * balanced `external_bank_movement` entry between the bank and external
 * equity (money in: bank −X, external_equity +X; out: the reverse — the bank
 * sign rule, spec 008 R15). The entry posts now whatever the statement date,
 * so a closed day never changes. The optional proof is a staff upload token.
 */
final class RecordBankMovementAction
{
    public function __construct(
        private readonly PostLedgerEntryAction $post,
        private readonly UploadTokenStore $tokens,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $staff, BankMovementKind $kind, string $direction, string $amount, string $occurredOn, string $reason,
        ?string $proofToken = null, ?RequestContext $ctx = null): BankMovement
    {
        $amount = Money::fixed4($amount);
        $signed = $direction === 'in' ? $amount : '-'.$amount;

        $proof = null;
        if ($proofToken !== null) {
            $proof = $this->tokens->resolveEntry($proofToken, CreateStaffUploadAction::owner($staff), UploadPurpose::BANK_MOVEMENT_PROOF)
                ?? throw DomainApiException::uploadTokenInvalid();
        }

        $movement = DB::transaction(function () use ($staff, $kind, $signed, $amount, $direction, $occurredOn, $reason, $proof, $ctx) {
            $txnId = null;
            if ($kind->postsToLedger()) {
                [$bank, $equity] = DatabaseActor::ledger(fn () => [Account::internal(AccountKind::BANK), Account::internal(AccountKind::EXTERNAL_EQUITY)]);
                $txnId = $this->post->handle(new LedgerEntry(
                    LedgerEventKind::EXTERNAL_BANK_MOVEMENT,
                    [new LedgerLine($bank, Money::fixed4(bcmul($signed, '-1', 4))), new LedgerLine($equity, Money::fixed4($signed))],
                    actorStaffId: $staff->staff_id,
                    memo: $kind->label().' · '.$reason,
                ))->ledger_txn_id;
            }

            $movement = BankMovement::query()->create([
                'kind' => $kind,
                'amount' => $signed,
                'occurred_on' => $occurredOn,
                'reason' => $reason,
                'proof_ref' => $proof['storage_ref'] ?? null,
                'proof_mime' => $proof === null ? null : ($proof['mime'] ?? 'application/octet-stream'),
                'recorded_by' => $staff->staff_id,
                'ledger_txn_id' => $txnId,
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->audit->execute(AuditEvent::BANK_MOVEMENT_RECORDED, 'success',
                ['kind' => $kind->value, 'direction' => $direction, 'amount' => $amount, 'occurred_on' => $occurredOn,
                    'has_proof' => $proof !== null, 'ledger_txn_id' => $txnId],
                'bank_movement', $movement->movement_id, $ctx, actorStaffId: $staff->staff_id, reason: $reason);

            return $movement;
        });

        if ($proofToken !== null) {
            $this->tokens->forget($proofToken);
        }

        return $movement->refresh();
    }
}
