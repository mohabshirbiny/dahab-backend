<?php

namespace App\Http\Resources\Staff;

use App\Models\Compensation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** One compensation payment on the Compensation page (spec 015 FR-001). */
#[OA\Schema(
    schema: 'StaffCompensation',
    required: ['id', 'paid_at', 'customer', 'party', 'amount', 'reason', 'reason_label', 'note', 'paid_by', 'dispute', 'order'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'customer', type: 'object', description: '{id, display_ref, name}'),
        new OA\Property(property: 'party', type: 'string', enum: ['buyer', 'seller'], nullable: true, description: 'Which side of the named order was paid; null without an order'),
        new OA\Property(property: 'amount', type: 'string', example: '800.0000'),
        new OA\Property(property: 'reason', type: 'string', enum: ['igi_delay', 'dahab_mistake', 'wasted_trip', 'dispute_settlement', 'goodwill']),
        new OA\Property(property: 'reason_label', type: 'string'),
        new OA\Property(property: 'note', type: 'string'),
        new OA\Property(property: 'paid_by', type: 'object', description: '{id, name}'),
        new OA\Property(property: 'dispute', type: 'object', nullable: true, description: '{id, ref}; null when paid outside a dispute'),
        new OA\Property(property: 'order', type: 'object', nullable: true, description: '{id, ref}'),
    ],
)]
class CompensationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Compensation $c */
        $c = $this->resource;

        return [
            'id' => $c->compensation_id,
            'paid_at' => $c->paid_at->toIso8601String(),
            'customer' => ['id' => $c->customer_id, 'display_ref' => $c->customer?->display_ref, 'name' => $c->customer?->full_name],
            'party' => $c->party,
            'amount' => bcadd((string) $c->amount, '0', 4),
            'reason' => $c->reason->value,
            'reason_label' => $c->reason->label(),
            'note' => $c->note,
            'paid_by' => ['id' => $c->paid_by, 'name' => $c->payer?->full_name],
            'dispute' => $c->dispute_id === null ? null : ['id' => $c->dispute_id, 'ref' => $c->dispute?->dispute_ref],
            'order' => $c->order_id === null ? null : ['id' => $c->order_id, 'ref' => $c->order?->order_ref],
        ];
    }
}
