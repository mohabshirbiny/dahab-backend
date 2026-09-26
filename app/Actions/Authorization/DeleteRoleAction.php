<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/** Delete a role nobody holds (spec 002 FR-012, FR-026, FR-055). */
final class DeleteRoleAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, StaffRoleModel $role, ?string $reason): void
    {
        $this->withAuthzLock(function () use ($actor, $role, $reason) {
            $role = StaffRoleModel::query()->lockForUpdate()->findOrFail($role->id);

            $this->guard->assertNotOwnRole($actor, $role);

            $holders = $role->holderCount();
            if ($holders > 0) {
                throw DomainApiException::roleInUse($holders);
            }

            ReasonRule::assertPresent($reason);

            $snapshot = [
                'role_id' => $role->id,
                'role' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'requires_mfa' => $role->requires_mfa,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ];

            $role->delete();

            $this->audit->execute(
                AuditEvent::ROLE_DELETED,
                'success',
                ['role_id' => $snapshot['role_id'], 'role' => $snapshot['role']],
                entityType: 'role',
                actorStaffId: $actor->staff_id,
                before: $snapshot,
                reason: $reason,
            );
        });
    }
}
