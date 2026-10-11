<?php

namespace App\Notifications\Contracts;

use App\Models\Staff;

/** A notification that can write a Dashboard notice through the `staff_notice` channel (spec 021). */
interface StaffNoticeNotification
{
    /**
     * @return array{kind: string, severity: string, title: string, body: string, params: array<string, mixed>, link: ?string, audit_id: ?int}|null
     */
    public function toStaffNotice(Staff $notifiable): ?array;
}
