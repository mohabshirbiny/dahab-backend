<?php

namespace App\Http\Resources\Pricing;

use App\Models\GoldPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardGoldPrice',
    description: 'A gold price that took effect (spec 005). entered_by and reason are set for manual prices.',
    required: ['id', 'source', 'bid_24k', 'ask_24k', 'effective_at', 'entered_by', 'reason'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'source', type: 'string', enum: ['feed', 'manual']),
        new OA\Property(property: 'bid_24k', type: 'string', example: '7944.0000'),
        new OA\Property(property: 'ask_24k', type: 'string', example: '7990.0000'),
        new OA\Property(property: 'effective_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'entered_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'confirmed_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ],
)]
class GoldPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GoldPrice $p */
        $p = $this->resource;
        $p->loadMissing(['manual.enteredBy', 'manual.confirmedBy']);

        return [
            'id' => $p->gold_price_id,
            'source' => $p->source->value,
            'bid_24k' => (string) $p->bid_24k,
            'ask_24k' => (string) $p->ask_24k,
            'effective_at' => $p->effective_at?->toIso8601String(),
            'entered_by' => StaffRef::of($p->manual?->enteredBy),
            'confirmed_by' => StaffRef::of($p->manual?->confirmedBy),
            'reason' => $p->manual?->reason,
        ];
    }
}
