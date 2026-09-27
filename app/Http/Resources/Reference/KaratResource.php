<?php

namespace App\Http\Resources\Reference;

use App\Models\Karat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardKarat',
    description: 'A karat sellers can choose (spec 004). Code and purity are immutable.',
    required: ['code', 'purity', 'is_enabled', 'sort_order'],
    properties: [
        new OA\Property(property: 'code', type: 'integer', example: 21),
        new OA\Property(property: 'purity', type: 'string', description: 'Five decimal places', example: '0.87500'),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'sort_order', type: 'integer'),
    ],
)]
class KaratResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Karat $k */
        $k = $this->resource;

        return [
            'code' => $k->karat_code,
            'purity' => $k->purity_ratio,
            'is_enabled' => (bool) $k->is_enabled,
            'sort_order' => $k->sort_order,
        ];
    }
}
