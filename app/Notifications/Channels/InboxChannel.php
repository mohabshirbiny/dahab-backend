<?php

namespace App\Notifications\Channels;

use App\Models\Customer;
use App\Notifications\Contracts\InboxNotification;
use App\Support\DatabaseActor;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The `inbox` notification channel (spec 017 research R5): the third channel
 * next to SMS and email. Writes one `customer_notification` row in the
 * `system` scope — a notification may be sent from another customer's or a
 * staff request, and only an elevated scope inserts inbox rows. Idempotent:
 * the notification id is the dedupe key, so a retried send writes nothing new.
 */
final class InboxChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof Customer || ! $notification instanceof InboxNotification) {
            return;
        }

        $message = $notification->toInbox($notifiable);
        if ($message === null) {
            return;
        }

        $dedupe = $notification->id ?: (string) Str::uuid();

        DatabaseActor::elevate('system', fn () => DB::table('customer_notification')->insertOrIgnore([
            'customer_id' => $notifiable->customer_id,
            'type' => $message->type,
            'params' => json_encode((object) $message->params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'link_kind' => $message->linkKind->value,
            'link_id' => $message->linkId,
            'title_en' => Str::limit($message->titleEn, 200, ''),
            'title_ar' => Str::limit($message->titleAr, 200, ''),
            'body_en' => Str::limit($message->bodyEn, 1000, ''),
            'body_ar' => Str::limit($message->bodyAr, 1000, ''),
            'dedupe_key' => $dedupe,
        ]));
    }
}
