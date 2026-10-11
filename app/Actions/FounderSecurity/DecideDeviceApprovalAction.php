<?php

namespace App\Actions\FounderSecurity;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * The other founder approves or refuses a held device (spec 021 research R6). The actor is an
 * active founder who is not frozen and not the requester (403 `founder_self_action`, backed by
 * the CHECKs `approver_is_not_self` / `refuser_is_not_self`); the request must still be pending
 * (409 `device_approval_not_pending`). The optional note is stored and is the audit reason.
 * A refusal freezes nothing: freezing is a separate, deliberate action.
 */
final class DecideDeviceApprovalAction
{
    public const APPROVE = 'approve';

    public const REFUSE = 'refuse';

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param self::APPROVE|self::REFUSE $decision */
    public function handle(Staff $actor, string $approvalId, string $decision, ?string $note): FounderDeviceApproval
    {
        return DB::transaction(function () use ($actor, $approvalId, $decision, $note) {
            $approval = FounderDeviceApproval::query()->lockForUpdate()->findOrFail($approvalId);

            if (! $actor->is_founder) {
                throw DomainApiException::founderOnly();
            }
            if ($actor->activeFreeze()->exists()) {
                throw AuthApiException::accountFrozen();
            }
            if ($approval->staff_id === $actor->staff_id) {
                throw DomainApiException::founderSelfAction();
            }
            if (! $approval->isPending()) {
                throw DomainApiException::deviceApprovalNotPending();
            }

            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $approve = $decision === self::APPROVE;

            $approval->update($approve
                ? ['approved_by' => $actor->staff_id, 'approved_at' => now(), 'decision_note' => $note]
                : ['refused_by' => $actor->staff_id, 'refused_at' => now(), 'decision_note' => $note]);

            $this->audit->execute(
                $approve ? AuditEvent::FOUNDER_DEVICE_APPROVED : AuditEvent::FOUNDER_DEVICE_REFUSED,
                'success',
                ['approval_id' => $approval->approval_id, 'founder_id' => $approval->staff_id, 'device_ref' => $approval->deviceRef()],
                entityType: 'staff',
                entityId: $approval->staff_id,
                actorStaffId: $actor->staff_id,
                reason: $note,
            );

            return $approval->load(['staff', 'approver', 'refuser']);
        });
    }
}
