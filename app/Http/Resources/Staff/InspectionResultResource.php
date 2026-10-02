<?php

namespace App\Http\Resources\Staff;

use App\Models\InspectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One inspection result (spec 012 FR-011, research R9). Measurements and the
 * derived outcome only: no price, no wallet, no names — an inspector reads it
 * (Part 1 §3.4, InspectorLeakTest).
 */
#[OA\Schema(
    schema: 'InspectionResult',
    description: 'An immutable inspection result (spec 012). Weights 3-dp strings. Never carries money or names.',
    required: ['inspection_id', 'order_id', 'branch_id', 'inspected_by', 'inspected_at', 'stated_karat', 'measured_karat', 'stated_weight_g', 'measured_weight_g', 'weight_diff_pct', 'karat_mismatch', 'is_counterfeit', 'stone_below_claim', 'outcome', 'supersedes_id'],
    properties: [
        new OA\Property(property: 'inspection_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'branch_id', type: 'integer'),
        new OA\Property(property: 'inspected_by', type: 'string', format: 'uuid'),
        new OA\Property(property: 'inspected_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'stated_karat', type: 'integer', nullable: true),
        new OA\Property(property: 'measured_karat', type: 'integer', nullable: true),
        new OA\Property(property: 'stated_weight_g', type: 'string', nullable: true),
        new OA\Property(property: 'measured_weight_g', type: 'string', nullable: true),
        new OA\Property(property: 'weight_diff_pct', type: 'string', nullable: true),
        new OA\Property(property: 'measured_stone_grade', type: 'string', nullable: true),
        new OA\Property(property: 'certificate_number', type: 'string', nullable: true),
        new OA\Property(property: 'inspector_note', type: 'string', nullable: true),
        new OA\Property(property: 'karat_mismatch', type: 'boolean'),
        new OA\Property(property: 'is_counterfeit', type: 'boolean'),
        new OA\Property(property: 'stone_below_claim', type: 'boolean'),
        new OA\Property(property: 'outcome', type: 'string', enum: ['pass', 'weight_adjust', 'stone_regrade', 'karat_cancel', 'fake_cancel']),
        new OA\Property(property: 'supersedes_id', type: 'string', format: 'uuid', nullable: true),
    ],
)]
class InspectionResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /**
     * A row of the Inspections list (research R18): the result plus the order's
     * reference and state, the piece, the seller's display reference, the
     * branch and when the piece was received. Still no money.
     *
     * @param  list<string>  $superseded
     * @return array<string, mixed>
     */
    public static function listRow(InspectionResult $r, array $superseded): array
    {
        $order = $r->order;
        $received = $order?->stateChanges->first(fn ($c) => $c->to_state->value === 'at_inspection')?->changed_at;
        $l = $order?->listing;

        return self::shape($r) + [
            'order_ref' => $order?->order_ref,
            'order_state' => $order?->state->value,
            'piece' => $l === null ? null : [
                'listing_id' => $l->listing_id,
                'category' => $l->category->value,
                'piece_type' => ['id' => $l->piece_type_id, 'name_en' => $l->pieceType?->name_en, 'name_ar' => $l->pieceType?->name_ar],
            ],
            'seller_ref' => $order?->seller?->display_ref,
            'branch' => ['id' => $r->branch_id, 'name_en' => $r->branch?->name_en, 'name_ar' => $r->branch?->name_ar],
            'received_at' => $received?->toIso8601String(),
            'superseded' => in_array($r->inspection_id, $superseded, true),
        ];
    }

    /** @return array<string, mixed> */
    public static function shape(InspectionResult $r, bool $withOrder = false): array
    {
        return [
            'inspection_id' => $r->inspection_id,
            'order_id' => $r->order_id,
            'branch_id' => $r->branch_id,
            'inspected_by' => $r->inspected_by,
            'inspected_at' => $r->created_at->toIso8601String(),
            'stated_karat' => $r->stated_karat,
            'measured_karat' => $r->measured_karat,
            'stated_weight_g' => $r->stated_weight_g === null ? null : (string) $r->stated_weight_g,
            'measured_weight_g' => $r->measured_weight_g === null ? null : (string) $r->measured_weight_g,
            'weight_diff_pct' => $r->weight_diff_pct === null ? null : (string) $r->weight_diff_pct,
            'measured_stone_grade' => $r->measured_stone_grade,
            'certificate_number' => $r->certificate_number,
            'inspector_note' => $r->inspector_note,
            'karat_mismatch' => $r->karat_mismatch,
            'is_counterfeit' => $r->is_counterfeit,
            'stone_below_claim' => $r->stone_below_claim,
            'outcome' => $r->outcome->value,
            'supersedes_id' => $r->supersedes_id,
        ];
    }
}
