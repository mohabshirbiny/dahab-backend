<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use App\Support\Authorization\RoleEscalationGuard;

/** Create a Dashboard-managed role (spec 002 FR-010, FR-025). */
final class CreateRoleAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{name: string, display_name: string, description?: ?string, requires_mfa?: bool, permissions: list<string>, reason?: ?string}  $data
     */
    public function handle(Staff $actor, array $data): StaffRoleModel
    {
        $permissions = array_values(array_unique($data['permissions']));
        sort($permissions);

        return $this->withAuthzLock(function () use ($actor, $data, $permissions) {
            $this->guard->assertCanChangePermissions($actor, $permissions, [], 'role', null);

            $role = StaffRoleModel::query()->create([
                'name' => $data['name'],
                'guard_name' => Staff::GUARD,
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'requires_mfa' => (bool) ($data['requires_mfa'] ?? false),
            ]);
            $role->syncPermissions($permissions);

            $this->audit->execute(
                AuditEvent::ROLE_CREATED,
                'success',
                [
                    'role_id' => $role->id,
                    'role' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'requires_mfa' => $role->requires_mfa,
                    'permissions' => $permissions,
                ],
                entityType: 'role',
                actorStaffId: $actor->staff_id,
                reason: $data['reason'] ?? null,
            );

            return $role->load('permissions');
        });
    }
}
