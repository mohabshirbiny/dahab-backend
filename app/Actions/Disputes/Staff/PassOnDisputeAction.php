<?php

namespace App\Actions\Disputes\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\DisputeChangeKind;
use App\Enums\DisputeState;
use App\Enums\StaffPermission;
use App\Exceptions\DomainApiException;
use App\Models\Dispute;
use App\Models\DisputeChange;
use App\Models\Staff;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pass a dispute to a named colleague (spec 014 FR-009; Part 2 §10): an active
 * staff member, not the caller, who also handles disputes. The dispute stays
 * open and becomes theirs; the note is for staff only and nothing is sent to
 * the customer. Audited.
 */
final class PassOnDisputeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $disputeId, string $assigneeId, string $note, ?RequestContext $ctx = null): Dispute
    {
        $note = trim($note);

        $assignee = Staff::query()->whereKey($assigneeId)->first();
        if ($assignee === null || $assignee->staff_id === $actor->staff_id || ! $assignee->is_active || $assignee->is_system
            || ! $assignee->can(StaffPermission::DISPUTE_HANDLE->value)) {
            throw DomainApiException::assigneeNotEligible();
        }

        return DB::transaction(function () use ($actor, $disputeId, $assignee, $note, $ctx) {
            $dispute = Dispute::query()->whereKey($disputeId)->lockForUpdate()->firstOrFail();
            if ($dispute->state === DisputeState::RESOLVED) {
                throw DomainApiException::illegalDisputeTransition();
            }

            $before = ['state' => $dispute->state->value, 'assigned_to' => $dispute->assigned_to];
            $dispute->forceFill([
                'state' => DisputeState::PASSED_ON,
                'assigned_to' => $assignee->staff_id,
                'passed_on_at' => CarbonImmutable::now(),
            ])->save();
            DisputeChange::query()->create([
                'dispute_id' => $dispute->dispute_id,
                'kind' => DisputeChangeKind::PASSED_ON,
                'actor_staff_id' => $actor->staff_id,
                'assigned_to' => $assignee->staff_id,
                'note' => $note,
            ]);

            $this->audit->execute(
                AuditEvent::DISPUTE_PASSED_ON,
                'success',
                ['dispute_ref' => $dispute->dispute_ref, 'assigned_to' => $assignee->staff_id, 'assignee' => $assignee->full_name],
                'dispute',
                $dispute->dispute_id,
                $ctx,
                actorStaffId: $actor->staff_id,
                before: $before,
                reason: $note,
            );

            return $dispute;
        });
    }
}
