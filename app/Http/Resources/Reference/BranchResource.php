<?php

namespace App\Http\Resources\Reference;

use App\Models\Branch;
use App\Models\BranchHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardBranch',
    description: 'An inspection branch with its weekly hours (spec 004).',
    required: ['id', 'name_en', 'name_ar', 'address_en', 'address_ar', 'timezone', 'is_enabled', 'hours'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name_en', type: 'string'),
        new OA\Property(property: 'name_ar', type: 'string'),
        new OA\Property(property: 'address_en', type: 'string'),
        new OA\Property(property: 'address_ar', type: 'string'),
        new OA\Property(property: 'timezone', type: 'string', example: 'Africa/Cairo'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'hours', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranchHour'), description: 'Sorted by dow, then opens_at'),
    ],
)]
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Branch $b */
        $b = $this->resource;

        return [
            'id' => $b->branch_id,
            'name_en' => $b->name_en,
            'name_ar' => $b->name_ar,
            'address_en' => $b->address_en,
            'address_ar' => $b->address_ar,
            'timezone' => $b->timezone,
            'is_enabled' => (bool) $b->is_enabled,
            'hours' => $b->hours->map(fn (BranchHour $h) => [
                'dow' => $h->dow,
                'opens_at' => substr($h->opens_at, 0, 5),
                'closes_at' => substr($h->closes_at, 0, 5),
            ])->values()->all(),
        ];
    }
}
