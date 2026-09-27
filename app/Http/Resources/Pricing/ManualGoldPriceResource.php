<?php

namespace App\Http\Resources\Pricing;

use App\Models\ManualGoldPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStaffRef',
    required: ['id', 'full_name'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'full_name', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardManualPrice',
    description: 'A manual gold price request (spec 005). A pending request past expires_at reads as lapsed.',
    required: ['id', 'bid_24k', 'ask_24k', 'deviation_pct', 'requires_confirmation', 'status', 'reason', 'entered_by', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'bid_24k', type: 'string', example: '7944.0000'),
        new OA\Property(property: 'ask_24k', type: 'string', example: '7990.0000'),
        new OA\Property(property: 'deviation_pct', type: 'string', nullable: true, example: '2.0025'),
        new OA\Property(property: 'requires_confirmation', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'effective', 'superseded', 'lapsed']),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'entered_by', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'confirmed_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class ManualGoldPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ManualGoldPrice $m */
        $m = $this->resource;
        $m->loadMissing(['enteredBy', 'confirmedBy']);

        return [
            'id' => $m->manual_gold_price_id,
            'bid_24k' => (string) $m->bid_24k,
            'ask_24k' => (string) $m->ask_24k,
            'deviation_pct' => $m->deviation_pct === null ? null : (string) $m->deviation_pct,
            'requires_confirmation' => $m->requires_confirmation,
            'status' => $m->displayStatus()->value,
            'reason' => $m->reason,
            'entered_by' => StaffRef::of($m->enteredBy),
            'confirmed_by' => StaffRef::of($m->confirmedBy),
            'confirmed_at' => $m->confirmed_at?->toIso8601String(),
            'expires_at' => $m->expires_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
