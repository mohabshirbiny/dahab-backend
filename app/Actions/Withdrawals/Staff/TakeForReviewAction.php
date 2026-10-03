<?php

namespace App\Actions\Withdrawals\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Withdrawals\Concerns\WorksWithdrawals;
use App\Enums\AuditEvent;
use App\Enums\WithdrawalState;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/** Claim for review (Part 2 §9 `review`; spec 013 US6): `requested → under_review`, the reviewer recorded. */
final class TakeForReviewAction
{
    use WorksWithdrawals;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $withdrawalId, ?RequestContext $ctx = null): Withdrawal
    {
        return DB::transaction(function () use ($actor, $withdrawalId, $ctx) {
            [$withdrawal] = $this->lockWithdrawal($withdrawalId);
            $from = $withdrawal->state;

            $this->moveWithdrawal($withdrawal, WithdrawalState::UNDER_REVIEW, [
                'reviewed_by' => $actor->staff_id,
                'review_started_at' => now(),
            ]);

            $this->audit->execute(AuditEvent::WITHDRAWAL_TAKEN_FOR_REVIEW, 'success',
                ['number' => $withdrawal->number(), 'amount' => $withdrawal->amount],
                'withdrawal', $withdrawal->withdrawal_id, $ctx, actorStaffId: $actor->staff_id,
                before: ['state' => $from->value]);

            return $withdrawal;
        });
    }
}
