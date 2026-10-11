<?php

namespace App\Actions\Authorization;

use App\Models\Staff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Staff members for the access-control screens (spec 002 FR-020). */
final class ListStaffAction
{
    /** @param  'active'|'disabled'|null  $status  spec 020 C6: omitted = all, as before */
    public function handle(?string $role, int $perPage, ?string $status = null): LengthAwarePaginator
    {
        return Staff::query()
            ->manageable()
            ->when($role !== null, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($status !== null, fn ($q) => $q->where('is_active', $status === 'active'))
            ->with(['roles.permissions:id,name', 'mfa', 'password:staff_id'])
            ->orderBy('full_name')
            ->orderBy('staff_id')
            ->paginate($perPage);
    }

    public function find(string $staffId): Staff
    {
        return Staff::query()
            ->manageable()
            ->with(['roles.permissions:id,name', 'mfa', 'password:staff_id'])
            ->findOrFail($staffId);
    }
}
