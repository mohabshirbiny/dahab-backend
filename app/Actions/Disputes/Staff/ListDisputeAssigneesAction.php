<?php

namespace App\Actions\Disputes\Staff;

use App\Enums\StaffPermission;
use App\Models\Staff;
use Illuminate\Support\Collection;

/**
 * Who a dispute can be passed to (spec 014 FR-009, research R17): active,
 * human staff holding `dispute.handle`, except the caller — so the pass-on
 * modal never needs `staff.view`.
 */
final class ListDisputeAssigneesAction
{
    /** @return Collection<int, Staff> */
    public function handle(Staff $caller): Collection
    {
        return Staff::query()
            ->where('is_active', true)->where('is_system', false)
            ->where('staff_id', '<>', $caller->staff_id)
            ->with('roles:id,name,display_name')
            ->orderBy('full_name')
            ->get(['staff_id', 'full_name', 'is_founder'])
            ->filter(fn (Staff $s) => $s->can(StaffPermission::DISPUTE_HANDLE->value))
            ->values();
    }
}
