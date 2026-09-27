<?php

namespace App\Http\Resources\Pricing;

use App\Models\KaratPriceAdjustmentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardAdjustmentChange',
    required: ['id', 'karat_code', 'side', 'old', 'new', 'changed_by', 'reason', 'changed_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'karat_code', type: 'integer'),
        new OA\Property(property: 'side', type: 'string', enum: ['buy', 'sell']),
        new OA\Property(property: 'old', ref: '#/components/schemas/DashboardAdjustment'),
        new OA\Property(property: 'new', ref: '#/components/schemas/DashboardAdjustment'),
        new OA\Property(property: 'changed_by', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
    ],
)]
class AdjustmentChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KaratPriceAdjustmentHistory $h */
        $h = $this->resource;

        return [
            'id' => $h->history_id,
            'karat_code' => $h->karat_code,
            'side' => $h->side->value,
            'old' => ['kind' => $h->old_kind->value, 'value' => (string) $h->old_value],
            'new' => ['kind' => $h->new_kind->value, 'value' => (string) $h->new_value],
            'changed_by' => StaffRef::of($h->changedBy),
            'reason' => $h->reason,
            'changed_at' => $h->changed_at?->toIso8601String(),
        ];
    }
}
