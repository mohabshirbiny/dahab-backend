<?php

namespace App\Support\FounderSecurity;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Notifications\FounderSecurityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Spec 021 research R11 (Story 3): tells every active founder — the actor included, a frozen
 * one included — of an event in AuditEvent::founderAlert().
 *
 *  - the Story 3 list (wallet adjustment, rates/settings, promo codes, manual gold price, staff
 *    created or disabled) only when the actor is a founder ("on either founder account");
 *  - the founder security events always, whoever the actor (the console trust is the system).
 *
 * Email + Dashboard notice for each; SMS too for a freeze (D21-3). Called by
 * RecordAuditLogAction after the transaction commits, so a rolled-back action never alerts.
 */
final class FounderAlerts
{
    /** Cheap check for the audit hook: is this event ever alerted? */
    public static function watches(AuditEvent $event): bool
    {
        return in_array($event, AuditEvent::founderAlert(), true);
    }

    /** Schedules the alert for after the current transaction (now when none is open). */
    public static function afterCommit(AuditLog $row): void
    {
        DB::afterCommit(fn () => self::dispatch($row));
    }

    public static function dispatch(AuditLog $row): void
    {
        $event = AuditEvent::tryFrom((string) $row->action);
        if ($event === null || ! self::watches($event)) {
            return;
        }

        $actor = $row->actor_staff_id !== null ? Staff::query()->find($row->actor_staff_id) : null;

        if (in_array($event, AuditEvent::founderSensitive(), true) && ! ($actor?->is_founder ?? false)) {
            return;
        }

        $founders = Staff::query()->manageable()->where('is_founder', true)->where('is_active', true)->get();
        if ($founders->isEmpty()) {
            return;
        }

        $sms = $event === AuditEvent::FOUNDER_FROZEN;
        if ($sms) {
            foreach ($founders as $founder) {
                if (blank($founder->phone)) {
                    Log::warning('founder_alert.sms_skipped', ['staff_id' => $founder->staff_id, 'event' => $event->value]);
                }
            }
        }

        $after = is_array($row->after_json) ? $row->after_json : [];

        $facts = [
            'event' => $event->value,
            'label' => $event->label(),
            'actor' => self::actorName($actor),
            'at' => ($row->created_at ?? now())->copy()->setTimezone('Africa/Cairo')->format('j M Y, H:i'),
            'ip' => $row->ip_address,
            // The device the event is about (a held request's device), else the actor's own.
            'device_ref' => isset($after['device_ref']) ? (string) $after['device_ref'] : (is_string($row->device_fingerprint) ? substr($row->device_fingerprint, 0, 8) : null),
            'platform' => $event === AuditEvent::FOUNDER_DEVICE_APPROVAL_REQUESTED && isset($after['platform']) ? (string) $after['platform'] : null,
            'entity_type' => $row->entity_type,
            'audit_id' => $row->audit_id !== null ? (int) $row->audit_id : null,
        ];

        $link = $event === AuditEvent::FOUNDER_DEVICE_APPROVAL_REQUESTED || $row->audit_id === null
            ? '/dashboard/security'
            : '/dashboard/audit?entry='.$row->audit_id;

        Notification::send($founders, new FounderSecurityNotification($facts, $link, $sms));
    }

    private static function actorName(?Staff $actor): string
    {
        if ($actor === null || $actor->is_system) {
            return 'System';
        }

        return $actor->full_name;
    }
}
