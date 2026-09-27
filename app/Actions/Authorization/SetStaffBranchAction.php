<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Set or clear the branch a staff member works at (spec 004 FR-040). The
 * branch narrows branch-scoped permissions, so it is an access change: not
 * on yourself, a reason is required, audited, and it applies on the
 * target's next request. The system actor cannot be targeted.
 */
final class SetStaffBranchAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, Staff $target, ?int $branchId, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $target, $branchId, $reason) {
            $target = Staff::query()->manageable()->lockForUpdate()->findOrFail($target->staff_id);

            $this->guard->assertNotSelf($actor, $target, 'change_own_branch');
            ReasonRule::assertPresent($reason);

            $before = $target->branch_id;
            if ($before === $branchId) {
                return $target;
            }

            $target->forceFill(['branch_id' => $branchId])->save();

            $this->audit->execute(
                AuditEvent::STAFF_BRANCH_CHANGED,
                'success',
                ['branch_id' => $branchId],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: ['branch_id' => $before],
                reason: $reason,
            );

            return $target;
        });
    }
}
