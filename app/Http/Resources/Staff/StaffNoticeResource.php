<?php

namespace App\Http\Resources\Staff;

use App\Models\StaffNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStaffNotice',
    description: 'Spec 021: a Dashboard notice (the bell). Staff-facing English; never a password, code, device id, amount or setting value. `link` is a Dashboard path.',
    required: ['id', 'kind', 'severity', 'title', 'body', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'kind', type: 'string', example: 'founder_alert'),
        new OA\Property(property: 'severity', type: 'string', enum: ['info', 'warning', 'critical']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'link', type: 'string', nullable: true, example: '/dashboard/security'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class StaffNoticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StaffNotice $n */
        $n = $this->resource;

        return [
            'id' => $n->notice_id,
            'kind' => $n->kind,
            'severity' => $n->severity,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'created_at' => $n->created_at?->toIso8601String(),
            'read_at' => $n->read_at?->toIso8601String(),
        ];
    }
}
