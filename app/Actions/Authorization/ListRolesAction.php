<?php

namespace App\Actions\Authorization;

use App\Models\Staff;
use App\Models\StaffRoleModel;
use Illuminate\Database\Eloquent\Collection;

/** Roles with their permissions and holder counts (spec 002 FR-013). */
final class ListRolesAction
{
    /** Roles are few; the cap keeps the list bounded. */
    public const LIMIT = 200;

    /** @return Collection<int, StaffRoleModel> */
    public function handle(): Collection
    {
        return StaffRoleModel::query()
            ->select('roles.*')
            ->withHolderCount()
            ->where('guard_name', Staff::GUARD)
            ->with('permissions:id,name')
            ->orderBy('display_name')
            ->limit(self::LIMIT)
            ->get();
    }

    public function find(string $name): StaffRoleModel
    {
        return StaffRoleModel::query()
            ->select('roles.*')
            ->withHolderCount()
            ->where('guard_name', Staff::GUARD)
            ->where('name', $name)
            ->with('permissions:id,name')
            ->firstOrFail();
    }
}
