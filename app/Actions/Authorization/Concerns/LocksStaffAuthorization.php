<?php

namespace App\Actions\Authorization\Concerns;

use App\Enums\StaffPermission;
use App\Exceptions\DomainApiException;
use App\Models\Staff;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs an authorization change serialized against every other one and
 * verifies the last-role-manager invariant before committing (spec 002
 * FR-023, research R7).
 *
 * The check runs after the change is applied, inside the transaction, so
 * one query covers every path (role edit, role delete, assignment). A
 * refusal throws, which rolls the change back.
 */
trait LocksStaffAuthorization
{
    /**
     * @template T
     *
     * @param  Closure(): T  $apply
     * @return T
     */
    protected function withAuthzLock(Closure $apply): mixed
    {
        $result = DB::transaction(function () use ($apply) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('dahab.staff_authz'))");
            }

            $result = $apply();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->assertRoleManagerRemains();

            return $result;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    private function assertRoleManagerRemains(): void
    {
        $managers = Staff::query()
            ->manageable()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', StaffPermission::ROLES_MANAGE->value))
            ->count();

        if ($managers === 0) {
            throw DomainApiException::lastRoleManager();
        }
    }
}
