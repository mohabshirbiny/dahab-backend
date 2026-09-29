<?php

namespace App\Actions\TopUp;

use App\Enums\AuditEvent;
use App\Enums\TopUpRejectReason;
use App\Enums\TopUpStatus;
use App\Models\Staff;
use App\Models\TopUp;
use App\Notifications\TopUpRejectedNotification;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Close a pending or on-hold notice without money (spec 009 US2 scenario 8,
 * FR-025a): the money never arrived, it duplicates another notice, or the
 * sender cannot be accepted. Final. The customer is told the reason — never
 * the staff note — after the change commits (FR-026).
 */
final class RejectTopUpAction
{
    public function __construct(private readonly ChangeTopUpStatusAction $change) {}

    public function handle(Staff $actor, string $topUpId, TopUpRejectReason $reason, string $note, ?RequestContext $ctx = null): TopUp
    {
        $note = trim($note);

        return DB::transaction(function () use ($actor, $topUpId, $reason, $note, $ctx) {
            $topUp = $this->change->handle($actor, $topUpId, TopUpStatus::REJECTED, fn () => [
                'reject_reason' => $reason,
                'reject_note' => $note,
                'rejected_by' => $actor->staff_id,
                'rejected_at' => now(),
            ], AuditEvent::TOPUP_REJECTED, $note, ['reject_reason' => $reason->value], $ctx);

            DB::afterCommit(fn () => $topUp->customer()->first()?->notify(new TopUpRejectedNotification($reason, $topUp->number())));

            return $topUp;
        });
    }
}
