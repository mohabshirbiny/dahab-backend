<?php

namespace App\Http\Resources\Pricing;

use App\Models\SettingHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSettingChange',
    required: ['id', 'key', 'old_value', 'new_value', 'changed_by', 'reason', 'changed_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'old_value', nullable: true, oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'boolean')]),
        new OA\Property(property: 'new_value', oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'boolean')]),
        new OA\Property(property: 'changed_by', ref: '#/components/schemas/DashboardStaffRef'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
    ],
)]
class SettingChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SettingHistory $h */
        $h = $this->resource;
        $bool = $h->new_bool !== null;

        return [
            'id' => $h->setting_history_id,
            'key' => $h->setting_key,
            'old_value' => $bool ? $h->old_bool : ($h->old_numeric === null ? null : (string) $h->old_numeric),
            'new_value' => $bool ? $h->new_bool : (string) $h->new_numeric,
            'changed_by' => StaffRef::of($h->changedBy),
            'reason' => $h->reason,
            'changed_at' => $h->changed_at?->toIso8601String(),
        ];
    }
}
