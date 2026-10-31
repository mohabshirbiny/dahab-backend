<?php

namespace App\Http\Resources\Reference;

use App\Enums\ListingState;
use App\Models\Lab;
use App\Models\Listing;
use App\Support\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardLab',
    required: ['id', 'name_en', 'name_ar', 'certificate_own_cost_egp', 'certificate_other_cost_egp', 'certificate_none_cost_egp', 'gold_inspection_cost_egp', 'is_enabled', 'live_pieces'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name_en', type: 'string'),
        new OA\Property(property: 'name_ar', type: 'string'),
        new OA\Property(property: 'certificate_own_cost_egp', type: 'string'),
        new OA\Property(property: 'certificate_other_cost_egp', type: 'string'),
        new OA\Property(property: 'certificate_none_cost_egp', type: 'string'),
        new OA\Property(property: 'gold_inspection_cost_egp', type: 'string'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'live_pieces', type: 'integer', description: 'Live pieces with this lab: they leave the market when it is disabled'),
    ],
)]
class LabResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lab $l */
        $l = $this->resource;

        return [
            'id' => $l->lab_id,
            'name_en' => $l->name_en,
            'name_ar' => $l->name_ar,
            'certificate_own_cost_egp' => Money::fixed4((string) $l->certificate_own_cost_egp),
            'certificate_other_cost_egp' => Money::fixed4((string) $l->certificate_other_cost_egp),
            'certificate_none_cost_egp' => Money::fixed4((string) $l->certificate_none_cost_egp),
            'gold_inspection_cost_egp' => Money::fixed4((string) $l->gold_inspection_cost_egp),
            'is_enabled' => (bool) $l->is_enabled,
            'live_pieces' => Listing::query()->withoutEagerLoads()->where('lab_id', $l->lab_id)->where('state', ListingState::LIVE->value)->count(),
        ];
    }
}
