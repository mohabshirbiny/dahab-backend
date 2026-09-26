<?php

namespace App\Support\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\StaffRoleModel;
use Illuminate\Support\Facades\DB;

/**
 * No self-escalation (spec 002 FR-025/FR-026, Clarification Q1): a role
 * manager may only add or remove permissions they hold, may not edit a role
 * they hold, and may not change their own role assignment.
 *
 * A refusal is audited (`authz.escalation_denied`) and thrown as
 * `403 escalation_denied`. The audit row is written after the surrounding
 * transaction has rolled back, so the refusal is recorded even though the
 * attempted change is not.
 */
final class RoleEscalationGuard
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    public function assertCanChangePermissions(Staff $actor, array $added, array $removed, string $entityType, string|int|null $entityId): void
    {
        $held = $actor->effectivePermissionCodes();
        $offending = array_values(array_diff(array_unique([...$added, ...$removed]), $held));

        if ($offending !== []) {
            $this->deny($actor, 'change_unheld_permissions', $offending, $entityType, $entityId);
        }
    }

    public function assertNotOwnRole(Staff $actor, StaffRoleModel $role): void
    {
        if ($actor->hasRole($role->name, Staff::GUARD)) {
            $this->deny($actor, 'edit_own_role', [], 'role', $role->id);
        }
    }

    /**
     * @param  list<string>  $addedRoleNames
     * @param  list<string>  $removedRoleNames
     */
    public function assertCanAssign(Staff $actor, Staff $target, array $addedRoleNames, array $removedRoleNames): void
    {
        if ($actor->is($target)) {
            $this->deny($actor, 'change_own_roles', [], 'staff', $target->staff_id);
        }

        $touched = array_unique([...$addedRoleNames, ...$removedRoleNames]);
        if ($touched === []) {
            return;
        }

        $permissions = StaffRoleModel::query()
            ->where('guard_name', Staff::GUARD)
            ->whereIn('name', $touched)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (StaffRoleModel $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        $offending = array_values(array_diff($permissions, $actor->effectivePermissionCodes()));

        if ($offending !== []) {
            $this->deny($actor, 'assign_role_with_unheld_permissions', $offending, 'staff', $target->staff_id);
        }
    }

    /** @param  list<string>  $offending */
    private function deny(Staff $actor, string $attempt, array $offending, string $entityType, string|int|null $entityId): never
    {
        // audit_log.entity_id is a UUID: staff ids fit, role ids (bigint) go in the payload.
        $record = fn () => $this->audit->execute(
            AuditEvent::ESCALATION_DENIED,
            'denied',
            ['attempt' => $attempt, 'offending_permissions' => $offending]
                + ($entityType === 'role' ? ['role_id' => $entityId] : []),
            entityType: $entityType,
            entityId: $entityType === 'staff' ? (string) $entityId : null,
            actorStaffId: $actor->staff_id,
        );

        DB::transactionLevel() > 0
            ? DB::afterRollBack($record)
            : $record();

        throw DomainApiException::escalationDenied();
    }
}
