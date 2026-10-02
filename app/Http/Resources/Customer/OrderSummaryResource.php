<?php

namespace App\Http\Resources\Customer;

use App\Models\Branch;
use App\Models\Order;
use OpenApi\Attributes as OA;

/**
 * An order as the buyer and the seller see it (spec 011 FR-009, FR-017). No
 * order endpoints exist yet: this shape rides on the buyer's request and the
 * seller's listing. It never names the other party.
 */
#[OA\Schema(
    schema: 'OrderSummary',
    description: 'The order a buy request became (spec 011). `state` is awaiting_delivery, or cancelled_staff when Dahab cancelled the sale (then with the time and reason; the buyer\'s deposit is back in their wallet).',
    required: ['order_ref', 'state', 'branch', 'accepted_at', 'reach_branch_deadline', 'locked_total_price', 'cancelled_at', 'cancel_reason'],
    properties: [
        new OA\Property(property: 'order_ref', type: 'string', example: 'DH-2026-000001'),
        new OA\Property(property: 'state', type: 'string', example: 'awaiting_delivery', description: 'An order_state value; clients must tolerate ones they do not know'),
        new OA\Property(property: 'branch', ref: '#/components/schemas/OrderBranch'),
        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reach_branch_deadline', type: 'string', format: 'date-time', description: 'The seller must bring the piece to the branch by then (working hours at that branch)'),
        new OA\Property(property: 'locked_total_price', type: 'string', example: '58200.0000'),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancel_reason', type: 'string', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'OrderBranch',
    required: ['id', 'name_en', 'name_ar', 'address_en', 'address_ar'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name_en', type: 'string'),
        new OA\Property(property: 'name_ar', type: 'string'),
        new OA\Property(property: 'address_en', type: 'string', nullable: true),
        new OA\Property(property: 'address_ar', type: 'string', nullable: true),
    ],
)]
final class OrderSummaryResource
{
    /** @return array<string, mixed>|null */
    public static function shape(?Order $order): ?array
    {
        if ($order === null) {
            return null;
        }

        /** @var Branch|null $branch */
        $branch = $order->branch;

        return [
            'order_ref' => $order->order_ref,
            'state' => $order->state->value,
            'branch' => $branch === null ? null : [
                'id' => $branch->branch_id,
                'name_en' => $branch->name_en,
                'name_ar' => $branch->name_ar,
                'address_en' => $branch->address_en,
                'address_ar' => $branch->address_ar,
            ],
            'accepted_at' => $order->accepted_at->toIso8601String(),
            'reach_branch_deadline' => $order->reach_branch_deadline->toIso8601String(),
            'locked_total_price' => bcadd((string) $order->locked_total_price, '0', 4),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancel_reason' => $order->cancel_reason,
        ];
    }
}
