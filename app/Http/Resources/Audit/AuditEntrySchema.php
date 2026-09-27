<?php

namespace App\Http\Resources\Audit;

use OpenApi\Attributes as OA;

/** OpenAPI shapes of the audit log viewer (spec 006); the data comes from AuditEntryPresenter. */
#[OA\Schema(
    schema: 'DashboardAuditEntry',
    required: ['id', 'at', 'actor', 'action', 'label', 'category', 'subject', 'before_summary', 'after_summary', 'reason', 'ip', 'outcome', 'entity'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'actor', description: 'staff: {type, id, name}; customer: {type, id, ref} (display_ref only); system: {type}', properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['staff', 'customer', 'system']),
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'ref', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'action', type: 'string', example: 'pricing.setting.changed'),
        new OA\Property(property: 'label', type: 'string', example: 'Setting changed'),
        new OA\Property(property: 'category', type: 'string', enum: ['money', 'pricing', 'accounts', 'identity', 'promo', 'reference', 'sessions', 'system']),
        new OA\Property(property: 'subject', type: 'string', nullable: true, example: 'commission.gold_pct'),
        new OA\Property(property: 'before_summary', type: 'string', nullable: true, example: '20'),
        new OA\Property(property: 'after_summary', type: 'string', nullable: true, example: '18'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'ip', type: 'string', nullable: true),
        new OA\Property(property: 'outcome', type: 'string', nullable: true, example: 'success'),
        new OA\Property(property: 'entity', properties: [
            new OA\Property(property: 'type', type: 'string'),
            new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true),
        ], type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardAuditEntryDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/DashboardAuditEntry'),
        new OA\Schema(properties: [
            new OA\Property(property: 'before', type: 'object', nullable: true, description: 'Exactly as recorded'),
            new OA\Property(property: 'after', type: 'object', nullable: true, description: 'Exactly as recorded'),
            new OA\Property(property: 'device_fingerprint', type: 'string', nullable: true),
        ]),
    ],
)]
final class AuditEntrySchema {}
