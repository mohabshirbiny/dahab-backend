<?php

namespace App\Actions\Authorization;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Auth\Staff\IssueStaffPasswordLinkAction;
use App\Actions\Authorization\Concerns\LocksStaffAuthorization;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;

/**
 * Changes a staff member's name, email or phone (docs/features/staff-edit-delete.md).
 *
 * Only the fields sent change. A founder's details are changed only by a founder (the phone
 * receives the new-device code). A new email voids every open set-password link; a member who has
 * not chosen a password yet gets a fresh invitation at the new address. A reason is required;
 * audited with the before and after values.
 */
final class UpdateStaffAction
{
    use LocksStaffAuthorization;

    private const FIELDS = ['full_name', 'email', 'phone'];

    public function __construct(
        private readonly IssueStaffPasswordLinkAction $links,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @param  array{full_name?: string, email?: string, phone?: string}  $data */
    public function handle(Staff $actor, string $staffId, array $data, ?string $reason): Staff
    {
        return $this->withAuthzLock(function () use ($actor, $staffId, $data, $reason) {
            $target = Staff::query()->manageable()->with('password:staff_id')->lockForUpdate()->findOrFail($staffId);

            if ($target->is_founder && ! $actor->is_founder) {
                throw DomainApiException::founderProtected("A founder's details are changed only by a founder.");
            }
            ReasonRule::assertPresent($reason);

            $after = [];
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $value = trim((string) $data[$field]);
                    $after[$field] = $field === 'email' ? mb_strtolower($value) : $value;
                }
            }
            $before = array_intersect_key($target->only(self::FIELDS), $after);
            $changed = array_diff_assoc($after, array_map(fn ($v) => (string) $v, $before));

            if ($changed === []) {
                return $target;
            }

            $target->forceFill($changed)->save();

            if (array_key_exists('email', $changed)) {
                if ($target->is_active && $target->password === null) {
                    $this->links->handle($target, IssueStaffPasswordLinkAction::INVITATION);
                } else {
                    IssueStaffPasswordLinkAction::voidOpenLinks($target->staff_id);
                }
            }

            $this->audit->execute(
                AuditEvent::STAFF_UPDATED,
                'success',
                $changed,
                entityType: 'staff',
                entityId: $target->staff_id,
                actorStaffId: $actor->staff_id,
                before: array_intersect_key($before, $changed),
                reason: $reason,
            );

            return $target;
        });
    }
}
