<?php

namespace App\Http\Resources\Pricing;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSetting',
    description: 'One tunable number (spec 005). Keys come with releases; only values change.',
    required: ['key', 'group', 'type', 'value', 'unit', 'description', 'min', 'max', 'integer', 'updated_by', 'updated_at'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'commission.gold_pct'),
        new OA\Property(property: 'group', type: 'string', enum: ['rates', 'operations'], description: 'rates: pricing.rates.manage; operations: settings.manage'),
        new OA\Property(property: 'type', type: 'string', enum: ['numeric', 'bool']),
        new OA\Property(property: 'value', description: 'Decimal string (4 dp) or boolean', oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'boolean')]),
        new OA\Property(property: 'unit', type: 'string', example: 'percent'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'min', type: 'string', nullable: true),
        new OA\Property(property: 'max', type: 'string', nullable: true),
        new OA\Property(property: 'integer', type: 'boolean', description: 'Whole numbers only'),
        new OA\Property(property: 'updated_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Setting $s */
        $s = $this->resource;
        $key = SettingKey::from($s->setting_key);
        $s->loadMissing('updatedBy');

        return [
            'key' => $key->value,
            'group' => $key->group()->value,
            'type' => $key->isBool() ? 'bool' : 'numeric',
            'value' => $key->isBool() ? (bool) $s->value_bool : (string) $s->value_numeric,
            'unit' => $s->unit,
            'description' => $s->description,
            'min' => $key->min(),
            'max' => $key->max(),
            'integer' => $key->isInteger(),
            'updated_by' => StaffRef::of($s->updatedBy),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
