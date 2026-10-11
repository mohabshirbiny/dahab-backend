<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Auth\Staff\IssueStaffPasswordLinkAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Creates a staff member without a password (spec 020 US3, research R8).
 *
 * The account is active with no `staff_password` row, so it cannot sign in until the member
 * uses the invitation link (48 hours) and chooses a password; no endpoint ever sets or returns
 * one. Roles are assigned in the same authorization lock through the escalation guard — the
 * actor must hold every permission of every role granted — and a reason is required.
 * Founder status is never settable here (`is_founder` is not fillable).
 */
final class CreateStaffAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly IssueStaffPasswordLinkAction $links,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @param  array{full_name: string, email: string, phone?: ?string, roles: list<string>, branch_id?: ?int}  $data
     */
    public function handle(Staff $actor, array $data, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $data, $reason) {
            ReasonRule::assertPresent($reason);

            $roles = array_values(array_unique($data['roles']));
            sort($roles);

            $staff = Staff::query()->create([
                'full_name' => trim($data['full_name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
                'branch_id' => $data['branch_id'] ?? null,
                'created_by' => $actor->staff_id,
            ]);

            // The actor may only grant roles whose every permission they hold; a refusal rolls
            // the new member back and is audited after the rollback.
            $this->guard->assertCanAssign($actor, $staff, $roles, []);
            $staff->syncRoles($roles);

            $this->audit->execute(
                AuditEvent::STAFF_CREATED,
                'success',
                ['roles' => $roles, 'branch_id' => $staff->branch_id, 'created_by' => $actor->staff_id],
                entityType: 'staff',
                entityId: $staff->staff_id,
                actorStaffId: $actor->staff_id,
                reason: $reason,
            );

            $this->links->handle($staff, IssueStaffPasswordLinkAction::INVITATION);

            return $staff;
        });
    }
}
