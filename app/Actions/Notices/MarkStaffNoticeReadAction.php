<?php

namespace App\Actions\Notices;

use App\Models\Staff;
use App\Models\StaffNotice;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Marks a staff member's own notices read (spec 021 R12). Only `read_at` ever changes; a notice
 * already read keeps its time. Someone else's notice is a 404, never a 403.
 */
final class MarkStaffNoticeReadAction
{
    public function one(Staff $staff, string $noticeId): StaffNotice
    {
        $notice = StaffNotice::query()->where('staff_id', $staff->staff_id)->whereKey($noticeId)->first()
            ?? throw new ModelNotFoundException;

        if ($notice->read_at === null) {
            $notice->forceFill(['read_at' => now()])->save();
        }

        return $notice;
    }

    public function all(Staff $staff): int
    {
        return StaffNotice::query()->where('staff_id', $staff->staff_id)->unread()->update(['read_at' => now()]);
    }
}
