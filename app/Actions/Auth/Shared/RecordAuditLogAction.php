<?php

namespace App\Actions\Auth\Shared;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RecordAuditLogAction
{
    /**
     * Writes a row to `audit_log` when we can attribute the event to a
     * concrete customer or staff row. Without an actor the DB CHECK
     * `audit_has_actor` would refuse the write, so we fall back to the
     * application log — the docs mandate the ledger holds attributed
     * events, and unattributed noise belongs elsewhere.
     */
    public function execute(
        AuditEvent $event,
        string $outcome,
        array $payload = [],
        string $entityType = 'auth',
        ?string $entityId = null,
        ?RequestContext $ctx = null,
        ?string $actorCustomerId = null,
        ?string $actorStaffId = null,
        ?array $before = null,
        ?string $reason = null,
    ): ?AuditLog {
        $ctx = $ctx ?? (app()->bound(RequestContext::class) ? app(RequestContext::class) : null);

        $customerId = $actorCustomerId ?? $ctx?->customerId;
        $staffId = $actorStaffId ?? $ctx?->staffId;

        if ($customerId === null && $staffId === null) {
            Log::warning('audit.unattributed', [
                'event' => $event->value,
                'outcome' => $outcome,
                'payload' => $payload,
                'ip' => $ctx?->ip,
                'device' => $ctx?->deviceFingerprintHash,
            ]);

            return null;
        }

        $attributes = [
            'actor_customer_id' => $customerId,
            'actor_staff_id' => $staffId,
            'action' => $event->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before,
            'reason' => $reason,
            'after_json' => array_merge(['outcome' => $outcome], $payload),
            'ip_address' => $ctx?->ip,
            'device_fingerprint' => $ctx?->deviceFingerprintHash,
        ];

        // Outside an elevated scope (a customer request) the database lets the
        // customer insert its own audit rows but not read them back, so the
        // insert must not use RETURNING (spec 003 research R8, FR-005).
        if (! DatabaseActor::isElevated() && DB::connection()->getDriverName() === 'pgsql') {
            DB::table('audit_log')->insert(array_map(
                fn ($v) => is_array($v) ? json_encode($v) : $v,
                $attributes,
            ));

            return (new AuditLog)->forceFill($attributes);
        }

        return AuditLog::query()->create($attributes);
    }
}
