<?php

namespace App\Http\Resources\Staff;

use App\Models\OrderExtensionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A seller's request for more time for staff (spec 014 FR-024, FR-027): the
 * Dashboard's *More time requested* card and *Extension requests* table.
 */
#[OA\Schema(
    schema: 'StaffExtensionRequest',
    required: ['id', 'reason', 'detail', 'state', 'requested_at', 'deadline_at_request'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order', type: 'object', description: '{id, ref, state, branch: {id, name_en, name_ar}}'),
        new OA\Property(property: 'seller', type: 'object', description: '{customer_id, display_ref}'),
        new OA\Property(property: 'reason', type: 'string', enum: ['travelling', 'emergency', 'branch_closed', 'other']),
        new OA\Property(property: 'reason_label', type: 'string'),
        new OA\Property(property: 'detail', type: 'string'),
        new OA\Property(property: 'state', type: 'string', enum: ['waiting', 'accepted', 'refused', 'lapsed']),
        new OA\Property(property: 'deadline_at_request', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deadline_now', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'seconds_left', type: 'integer', nullable: true),
        new OA\Property(property: 'extensions_before', type: 'integer', nullable: true),
        new OA\Property(property: 'requested_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'answered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'answered_by', type: 'object', nullable: true, description: '{staff_id, name}'),
        new OA\Property(property: 'hours_granted', type: 'integer', nullable: true),
        new OA\Property(property: 'answer_note', type: 'string', nullable: true),
    ],
)]
class ExtensionRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource, withOrder: true);
    }

    /** @return array<string, mixed> */
    public static function shape(OrderExtensionRequest $r, bool $withOrder): array
    {
        $order = $r->relationLoaded('order') ? $r->order : null;
        $deadline = $order?->reach_branch_deadline;

        return ($withOrder && $order !== null ? [
            'order' => [
                'id' => $order->order_id,
                'ref' => $order->order_ref,
                'state' => $order->state->value,
                'branch' => $order->branch === null ? null : ['id' => $order->branch->branch_id, 'name_en' => $order->branch->name_en, 'name_ar' => $order->branch->name_ar],
            ],
            'seller' => ['customer_id' => $r->seller_id, 'display_ref' => $order->seller?->display_ref],
        ] : []) + [
            'id' => $r->request_id,
            'reason' => $r->reason->value,
            'reason_label' => $r->reason->label(),
            'detail' => $r->detail,
            'state' => $r->state->value,
            'deadline_at_request' => $r->deadline_at_request->toIso8601String(),
            'deadline_now' => $deadline?->toIso8601String(),
            'seconds_left' => $deadline === null ? null : max(0, $deadline->getTimestamp() - now()->getTimestamp()),
            'extensions_before' => $r->getAttribute('extensions_before'),
            'requested_at' => $r->requested_at->toIso8601String(),
            'answered_at' => $r->answered_at?->toIso8601String(),
            'answered_by' => $r->answered_by === null ? null
                : ['staff_id' => $r->answered_by, 'name' => $r->relationLoaded('answerer') ? $r->answerer?->full_name : null],
            'hours_granted' => $r->hours_granted,
            'answer_note' => $r->answer_note,
        ];
    }
}
