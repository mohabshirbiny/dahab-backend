<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Edit a role's labels, MFA flag and/or permission set (spec 002 FR-011).
 * The actor must not hold the role (FR-026) and may only add or remove
 * permissions they hold (FR-025). A reason is required when the permission
 * set or the MFA flag actually changes (FR-055). One audit row per changed
 * aspect, so each row keeps a single meaning.
 */
final class UpdateRoleAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{display_name?: string, description?: ?string, requires_mfa?: bool, permissions?: list<string>, reason?: ?string}  $data
     */
    public function handle(Staff $actor, StaffRoleModel $role, array $data): StaffRoleModel
    {
        return $this->withAuthzLock(function () use ($actor, $role, $data) {
            $role = StaffRoleModel::query()->lockForUpdate()->findOrFail($role->id);

            $this->guard->assertNotOwnRole($actor, $role);

            $before = $role->permissions->pluck('name')->sort()->values()->all();
            $after = $before;
            $added = $removed = [];

            if (array_key_exists('permissions', $data)) {
                $after = array_values(array_unique($data['permissions']));
                sort($after);
                $added = array_values(array_diff($after, $before));
                $removed = array_values(array_diff($before, $after));
                $this->guard->assertCanChangePermissions($actor, $added, $removed, 'role', $role->id);
            }

            $mfaChanged = array_key_exists('requires_mfa', $data) && (bool) $data['requires_mfa'] !== $role->requires_mfa;
            $permissionsChanged = $added !== [] || $removed !== [];

            if ($mfaChanged || $permissionsChanged) {
                ReasonRule::assertPresent($data['reason'] ?? null);
            }

            $reason = $data['reason'] ?? null;
            $ref = ['role_id' => $role->id, 'role' => $role->name];

            $labels = array_intersect_key($data, array_flip(['display_name', 'description']));
            $labelChanges = array_filter($labels, fn ($value, $key) => $role->{$key} !== $value, ARRAY_FILTER_USE_BOTH);
            if ($labelChanges !== []) {
                $labelBefore = array_intersect_key($role->only(['display_name', 'description']), $labelChanges);
                $role->fill($labelChanges)->save();
                $this->record(AuditEvent::ROLE_UPDATED, $actor, $ref + $labelBefore, $ref + $labelChanges, $reason);
            }

            if ($mfaChanged) {
                $old = $role->requires_mfa;
                $role->forceFill(['requires_mfa' => (bool) $data['requires_mfa']])->save();
                $this->record(AuditEvent::ROLE_MFA_CHANGED, $actor, ['requires_mfa' => $old], $ref + ['requires_mfa' => $role->requires_mfa], $reason);
            }

            if ($permissionsChanged) {
                $role->syncPermissions($after);
                $this->record(
                    AuditEvent::ROLE_PERMISSIONS_CHANGED,
                    $actor,
                    ['permissions' => $before],
                    $ref + ['permissions' => $after, 'added' => $added, 'removed' => $removed],
                    $reason,
                );
            }

            return $role->fresh('permissions');
        });
    }

    private function record(AuditEvent $event, Staff $actor, array $before, array $after, ?string $reason): void
    {
        $this->audit->execute($event, 'success', $after, entityType: 'role', actorStaffId: $actor->staff_id, before: $before, reason: $reason);
    }
}
