<?php

namespace App\Actions\Notices;

use App\Models\Staff;
use App\Models\StaffNotice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A staff member's own Dashboard notices, newest first (spec 021 research R12). Any staff
 * member; reading your own notices is not audited, like the customer inbox.
 */
final class ListStaffNoticesAction
{
    public function handle(Staff $staff, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return StaffNotice::query()
            ->where('staff_id', $staff->staff_id)
            ->when($unreadOnly, fn ($q) => $q->unread())
            ->orderByDesc('created_at')
            ->orderByDesc('notice_id')
            ->paginate($perPage);
    }

    public function unreadCount(Staff $staff): int
    {
        return StaffNotice::query()->where('staff_id', $staff->staff_id)->unread()->count();
    }
}
