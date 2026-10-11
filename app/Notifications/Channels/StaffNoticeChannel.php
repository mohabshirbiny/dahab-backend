<?php

namespace App\Notifications\Channels;

use App\Models\Staff;
use App\Notifications\Contracts\StaffNoticeNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The `staff_notice` notification channel (spec 021 research R11), modelled on the customer
 * `inbox` channel: one `staff_notice` row per staff member. Staff tables carry no row-level
 * security, so no elevation is needed. Idempotent: the notification id plus the recipient is
 * the dedupe key, so a retried send writes nothing new.
 */
final class StaffNoticeChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Staff || ! $notification instanceof StaffNoticeNotification) {
            return;
        }

        $notice = $notification->toStaffNotice($notifiable);
        if ($notice === null) {
            return;
        }

        $dedupe = ($notification->id ?: (string) Str::uuid()).':'.$notifiable->staff_id;

        DB::table('staff_notice')->insertOrIgnore([
            'staff_id' => $notifiable->staff_id,
            'kind' => $notice['kind'],
            'severity' => $notice['severity'],
            'title' => Str::limit($notice['title'], 200, ''),
            'body' => Str::limit($notice['body'], 1000, ''),
            'params' => json_encode((object) $notice['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'link' => $notice['link'],
            'audit_id' => $notice['audit_id'],
            'dedupe_key' => $dedupe,
        ]);
    }
}
