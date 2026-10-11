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
 * Disables a staff member (spec 020 US3, research R9).
 *
 * Not yourself, never a founder, only an active member. Inside the authorization lock:
 * `is_active` goes false, every token is deleted, open set-password links are consumed, and
 * the last active role manager cannot be disabled (409 `last_role_manager`, the lock's own
 * post-check). Nothing is deleted: history keeps the member's name. A reason is required.
 */
final class DisableStaffAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $staffId, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $staffId, $reason) {
            $target = Staff::query()->manageable()->lockForUpdate()->findOrFail($staffId);

            $this->guard->assertNotSelf($actor, $target, 'disable_self');

            if ($target->is_founder) {
                throw DomainApiException::founderProtected();
            }
            if (! $target->is_active) {
                throw DomainApiException::staffAlreadyDisabled();
            }
            ReasonRule::assertPresent($reason);

            $target->forceFill(['is_active' => false])->save();
            $ended = $target->tokens()->delete();
            IssueStaffPasswordLinkAction::voidOpenLinks($target->staff_id);

            $this->audit->execute(
                AuditEvent::STAFF_DISABLED,
                'success',
                ['is_active' => false, 'tokens_revoked' => $ended],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: ['is_active' => true],
                reason: $reason,
            );

            return $target;
        });
    }
}
