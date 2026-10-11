<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Auth\Staff\IssueStaffPasswordLinkAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Models\StaffPassword;
use App\Support\Authorization\ReasonRule;
use App\Support\Authorization\RoleEscalationGuard;

/**
 * Enables a disabled staff member again (spec 020 US3, research R9, clarification C3).
 *
 * The old password is deleted, so the 48-hour link mailed here is the only way back in (the
 * account may have been disabled because of a compromise). The authenticator enrolment is
 * kept. Not yourself; a reason is required.
 */
final class EnableStaffAction
{
    use LocksStaffAuthorization;

    public function __construct(
        private readonly RoleEscalationGuard $guard,
        private readonly IssueStaffPasswordLinkAction $links,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, string $staffId, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $staffId, $reason) {
            $target = Staff::query()->manageable()->lockForUpdate()->findOrFail($staffId);

            $this->guard->assertNotSelf($actor, $target, 'enable_self');

            if ($target->is_active) {
                throw DomainApiException::staffNotDisabled();
            }
            ReasonRule::assertPresent($reason);

            $target->forceFill(['is_active' => true])->save();
            StaffPassword::query()->whereKey($target->staff_id)->delete();
            $this->links->handle($target, IssueStaffPasswordLinkAction::ENABLED);

            $this->audit->execute(
                AuditEvent::STAFF_ENABLED,
                'success',
                ['is_active' => true, 'password_cleared' => true],
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: ['is_active' => false],
                reason: $reason,
            );

            return $target;
        });
    }
}
