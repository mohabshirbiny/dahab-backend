<?php

namespace App\Http\Resources\Staff;

use App\Models\StaffNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStaffNotice',
    description: 'Spec 021: a Dashboard notice (the bell). Staff-facing English; never a password, code, device id, amount or setting value. `link` is a Dashboard path. Spec 026 adds work notices (`work_*` kinds): new work in a queue the staff member works, grouped while unread — `count` events, `created_at` the latest; the link opens the item for one, the queue page for several.',
    required: ['id', 'kind', 'severity', 'title', 'body', 'count', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'kind', type: 'string', enum: ['founder_alert', 'work_identity', 'work_topup', 'work_listing', 'work_order', 'work_dispute', 'work_withdrawal', 'work_payout_account', 'work_extension', 'work_listing_report'], example: 'work_listing'),
        new OA\Property(property: 'severity', type: 'string', enum: ['info', 'warning', 'critical']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'link', type: 'string', nullable: true, example: '/dashboard/listings'),
        new OA\Property(property: 'count', type: 'integer', minimum: 1, example: 3, description: 'Spec 026: events grouped in this notice (1 for a founder alert).'),
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
            'count' => (int) ($n->item_count ?? 1),
            'created_at' => $n->created_at?->toIso8601String(),
            'read_at' => $n->read_at?->toIso8601String(),
        ];
    }
}
