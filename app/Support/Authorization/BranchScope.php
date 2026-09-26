<?php

namespace App\Support\Authorization;

use App\Enums\StaffPermission;
use App\Exceptions\DomainApiException;
use App\Models\Staff;

/**
 * Branch-scoped permissions (spec 002 FR-041): a permission marked
 * `isBranchScoped()` only authorizes records of the staff member's own
 * branch, and authorizes nothing when no branch is assigned.
 *
 * Call it from an Action after the route's `staff.permission` check, with
 * the branch of the record being acted on (the IGI inspection flow is the
 * first user).
 */
final class BranchScope
{
    public static function assert(Staff $staff, StaffPermission $permission, int $recordBranchId): void
    {
        self::check($staff, $permission->isBranchScoped(), $recordBranchId);
    }

    /** The rule itself; `assert()` feeds it the permission's flag. */
    public static function check(Staff $staff, bool $scoped, int $recordBranchId): void
    {
        if (! $scoped) {
            return;
        }

        if ($staff->branch_id === null || (int) $staff->branch_id !== $recordBranchId) {
            throw DomainApiException::wrongBranch();
        }
    }
}
