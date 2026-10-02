<?php

namespace App\Http\Resources\Staff;

use App\Enums\OrderState;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A piece on the branch work list (spec 012 FR-010, research R18; Part 1
 * §3.4): only what receiving, inspecting and handing over need — the order
 * reference, the piece, the stated karat and weight. No price, no wallet, no
 * buyer or seller. Also the answer to a receive / result / handover for a
 * caller without `order.view`.
 */
#[OA\Schema(
    schema: 'WorkListItem',
    description: 'A piece at the branch (spec 012). Never carries money or names.',
    required: ['order_id', 'order_ref', 'state', 'task', 'piece_type', 'category', 'stated_karat', 'stated_weight_g', 'branch', 'since'],
    properties: [
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_ref', type: 'string'),
        new OA\Property(property: 'state', type: 'string'),
        new OA\Property(property: 'task', type: 'string', nullable: true, enum: ['receive', 'inspect', 'correct', 'handover', 'return_handover']),
        new OA\Property(property: 'piece_type', type: 'object', description: '{id, name_en, name_ar}'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'stated_karat', type: 'integer', nullable: true),
        new OA\Property(property: 'stated_weight_g', type: 'string', nullable: true),
        new OA\Property(property: 'latest_inspection', ref: '#/components/schemas/InspectionResult', nullable: true),
        new OA\Property(property: 'branch', type: 'object', description: '{id, name_en, name_ar}'),
        new OA\Property(property: 'since', type: 'string', format: 'date-time', description: 'When the order reached its current state'),
    ],
)]
class WorkListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $l = $o->listing;
        $latest = $o->latestInspection();
        $return = $o->sellerReturn;

        $task = match (true) {
            $o->state === OrderState::AWAITING_DELIVERY => 'receive',
            $o->state === OrderState::AT_INSPECTION => 'inspect',
            in_array($o->state, [OrderState::WEIGHT_ADJUST_PENDING, OrderState::AWAITING_BALANCE], true) => 'correct',
            $o->state === OrderState::READY_TO_COLLECT && $o->collection?->collected_at === null => 'handover',
            $return !== null && $return->isOpen() => 'return_handover',
            default => null,
        };

        return [
            'order_id' => $o->order_id,
            'order_ref' => $o->order_ref,
            'state' => $o->state->value,
            'task' => $task,
            'piece_type' => ['id' => $l->piece_type_id, 'name_en' => $l->pieceType?->name_en, 'name_ar' => $l->pieceType?->name_ar],
            'category' => $l->category->value,
            'stated_karat' => $l->karat_code,
            'stated_weight_g' => $l->stated_weight_g === null ? null : bcadd((string) $l->stated_weight_g, '0', 3),
            'latest_inspection' => $latest === null ? null : InspectionResultResource::shape($latest),
            'branch' => ['id' => $o->branch_id, 'name_en' => $o->branch?->name_en, 'name_ar' => $o->branch?->name_ar],
            'since' => ($o->stateChanges->last()?->changed_at ?? $o->accepted_at)->toIso8601String(),
        ];
    }
}
