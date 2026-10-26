<?php

namespace App\Http\Resources\Staff;

use App\Enums\OrderState;
use App\Models\Order;
use App\Support\Orders\HandoverIds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A piece on the branch work list (spec 012 FR-010, research R18; Part 1
 * §3.4): only what receiving, inspecting and handing over need — the order
 * reference, the piece, the stated karat and weight. No price, no wallet, no
 * buyer or seller. Also the answer to a receive / result / handover for a
 * caller without `order.view`. One exception: on a handover to a person the
 * buyer named to collect, `proxy` gives that person's name and phone so the
 * counter can check their ID (spec 014) — the ID photo stays behind its call;
 * `buyer_id` says which of the buyer's own ID images the counter may open.
 */
#[OA\Schema(
    schema: 'WorkListItem',
    description: 'A piece at the branch (spec 012). Never carries money, nor any name but the proxy the buyer named on a handover (spec 014).',
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
        new OA\Property(property: 'stone', type: 'object', nullable: true, description: 'App notes 2026-10-26: the stone as the seller stated it, for the inspector: {certificate_kind, carat, clarity, cut}; null for gold'),
        new OA\Property(property: 'certificates', type: 'array', description: 'The stone certificate files the seller uploaded: [{id, mime}], each through GET /dashboard/orders/{id}/certificates/{media}', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'mime', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'latest_inspection', ref: '#/components/schemas/InspectionResult', nullable: true),
        new OA\Property(property: 'branch', type: 'object', description: '{id, name_en, name_ar}'),
        new OA\Property(property: 'since', type: 'string', format: 'date-time', description: 'When the order reached its current state'),
        new OA\Property(property: 'proxy', type: 'object', nullable: true, description: 'Spec 014: on the handover task only, the person the buyer named to collect; the ID photo through GET /dashboard/orders/{id}/proxy-id',
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'phone', type: 'string'),
                new OA\Property(property: 'has_id_photo', type: 'boolean'),
                new OA\Property(property: 'id_kind', type: 'string', enum: ['egyptian_id', 'passport'], nullable: true, description: 'null when named before the kind was asked'),
                new OA\Property(property: 'has_back', type: 'boolean', description: 'The back of their ID was uploaded (…/proxy-id?side=back)'),
            ]),
        new OA\Property(property: 'buyer_id', type: 'object', nullable: true, description: 'docs/features/handover-id-check.md: on the handover task, the buyer\'s own verified ID to check when they collect in person; the images through GET /dashboard/orders/{id}/buyer-id?side=front|back',
            properties: [
                new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
                new OA\Property(property: 'has_back', type: 'boolean'),
            ]),
    ],
)]
class WorkListItemResource extends JsonResource
{
    /**
     * The order's stone certificate files: [{id, mime}], opened through the order (never a listing permission).
     *
     * @return list<array{id: string, mime: string}>
     */
    public static function certificates(Order $o): array
    {
        $files = $o->listing->relationLoaded('certificates') ? $o->listing->certificates : $o->listing->certificates()->get();

        return $files->map(fn ($m) => ['id' => $m->media_id, 'mime' => $m->mime])->values()->all();
    }

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
            // App notes 2026-10-26: the stone and its certificate as the seller stated them, for the inspector.
            'stone' => $l->stoneDetails(),
            'certificates' => self::certificates($o),
            'latest_inspection' => $latest === null ? null : InspectionResultResource::shape($latest),
            'branch' => ['id' => $o->branch_id, 'name_en' => $o->branch?->name_en, 'name_ar' => $o->branch?->name_ar],
            'since' => ($o->stateChanges->last()?->changed_at ?? $o->accepted_at)->toIso8601String(),
            'proxy' => $task === 'handover' && $o->collection->is_proxy ? [
                'name' => $o->collection->proxy_name,
                'phone' => $o->collection->proxy_phone,
                'has_id_photo' => $o->collection->proxy_id_storage_ref !== null,
            ] + HandoverIds::proxy($o->collection) : null,
            'buyer_id' => $task === 'handover' ? HandoverIds::buyer($o, $o->collection) : null,
        ];
    }
}
