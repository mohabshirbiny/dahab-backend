<?php

namespace App\Http\Resources\Staff;

use App\Models\AppTextVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffAppTextVersion',
    description: 'One version of a text: who wrote it, who published it and when (spec 019).',
    properties: [
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'state', type: 'string', enum: ['draft', 'published', 'superseded']),
        new OA\Property(property: 'is_default_marker', type: 'boolean', description: 'Ended the override: the app shows its own default'),
        new OA\Property(property: 'en', type: 'string', nullable: true),
        new OA\Property(property: 'ar', type: 'string', nullable: true),
        new OA\Property(property: 'edited_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'edited_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'published_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
        new OA\Property(property: 'bundle_version', type: 'integer', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'StaffAppTextDraft',
    description: 'The pending draft of one text (spec 019).',
    properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'draft_version', type: 'integer', description: 'Send it back as draft_version when saving again'),
        new OA\Property(property: 'en', type: 'string'),
        new OA\Property(property: 'ar', type: 'string'),
        new OA\Property(property: 'is_default_marker', type: 'boolean'),
        new OA\Property(property: 'is_hidden', type: 'boolean'),
        new OA\Property(property: 'edited_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'edited_by', ref: '#/components/schemas/DashboardStaffRef', nullable: true),
    ],
)]
class AppTextVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AppTextVersion $v */
        $v = $this->resource;
        $marker = $v->is_default_marker;

        return [
            'version' => $v->version,
            'state' => $v->state,
            'is_default_marker' => $marker,
            'en' => $marker ? null : $v->text_en,
            'ar' => $marker ? null : $v->text_ar,
            'edited_at' => $v->edited_at?->toIso8601String(),
            'edited_by' => $v->editor === null ? null : ['id' => $v->editor->staff_id, 'full_name' => $v->editor->full_name],
            'published_at' => $v->published_at?->toIso8601String(),
            'published_by' => $v->publisher === null ? null : ['id' => $v->publisher->staff_id, 'full_name' => $v->publisher->full_name],
            'bundle_version' => $v->bundle_version,
        ];
    }
}
