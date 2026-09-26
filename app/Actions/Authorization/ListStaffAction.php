<?php

namespace App\Actions\Authorization;

use App\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Staff members for the access-control screens (spec 002 FR-020). */
final class ListStaffAction
{
    public function handle(?string $role, int $perPage): LengthAwarePaginator
    {
        return Staff::query()
            ->manageable()
            ->when($role !== null, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->with(['roles.permissions:id,name', 'mfa'])
            ->orderBy('full_name')
            ->orderBy('staff_id')
            ->paginate($perPage);
    }

    public function find(string $staffId): Staff
    {
        return Staff::query()
            ->manageable()
            ->with(['roles.permissions:id,name', 'mfa'])
            ->findOrFail($staffId);
    }
}
