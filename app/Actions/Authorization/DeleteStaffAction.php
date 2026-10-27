<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Auth\Staff\IssueStaffPasswordLinkAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Deletes a staff member (docs/features/staff-edit-delete.md): a soft delete, final.
 *
 * The row stays (audit and every record point at it) with `deleted_at` set and `is_active`
 * false; the member leaves every list and lookup, cannot sign in, and their email is free for a
 * new account. Not yourself, never a founder; active or disabled. Every token is deleted and open
 * set-password links are voided; the last active role manager cannot go (409 `last_role_manager`).
 * A reason is required.
 */
final class DeleteStaffAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $staffId, ?string $reason): void
    {
        $this->withAuthzLock(function () use ($actor, $staffId, $reason) {
            $target = Staff::query()->manageable()->lockForUpdate()->findOrFail($staffId);

            $this->guard->assertNotSelf($actor, $target, 'delete_self');

            if ($target->is_founder) {
                throw DomainApiException::founderProtected('A founder account cannot be deleted.');
            }
            ReasonRule::assertPresent($reason);

            $wasActive = (bool) $target->is_active;
            $target->forceFill(['is_active' => false, 'deleted_at' => now()])->save();
            $ended = $target->tokens()->delete();
            IssueStaffPasswordLinkAction::voidOpenLinks($target->staff_id);

            $this->audit->execute(
                AuditEvent::STAFF_DELETED,
                'success',
                ['deleted' => true, 'tokens_revoked' => $ended, 'full_name' => $target->full_name, 'email' => $target->email],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: ['is_active' => $wasActive],
                reason: $reason,
            );
        });
    }
}
