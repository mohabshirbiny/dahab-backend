<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Replace the roles a staff member holds (spec 002 FR-021). Not on yourself,
 * and only roles whose every permission the actor holds may be added or
 * removed (FR-026). A reason is required (FR-055). Founder status is never
 * touched (FR-044). The system actor cannot be targeted (FR-061).
 */
final class SetStaffRolesAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @param  list<string>  $roles */
    public function handle(Staff $actor, Staff $target, array $roles, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $target, $roles, $reason) {
            $target = Staff::query()->manageable()->lockForUpdate()->findOrFail($target->staff_id);

            $before = $target->getRoleNames()->sort()->values()->all();
            $after = array_values(array_unique($roles));
            sort($after);
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            $this->guard->assertCanAssign($actor, $target, $added, $removed);
            ReasonRule::assertPresent($reason);

            $target->syncRoles($after);

            $this->audit->execute(
                AuditEvent::STAFF_ROLES_CHANGED,
                'success',
                ['roles' => $after, 'added' => $added, 'removed' => $removed],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: ['roles' => $before],
                reason: $reason,
            );

            return $target;
        });
    }
}
