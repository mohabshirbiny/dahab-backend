<?php

namespace App\Http\Resources\Staff;

use App\Enums\StaffPermission;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffAppText',
    description: 'One registered text of the customer app as staff edit it (spec 019 US1): the code default, what customers see now and the pending draft.',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'order.free_relist.card_title'),
        new OA\Property(property: 'kind', type: 'string', enum: ['app', 'faq', 'notification']),
        new OA\Property(property: 'area', type: 'string', example: 'Orders'),
        new OA\Property(property: 'description', type: 'string', description: 'Where it appears'),
        new OA\Property(property: 'default', properties: [new OA\Property(property: 'en', type: 'string'), new OA\Property(property: 'ar', type: 'string')], type: 'object', description: 'The text the app shows when nothing is published'),
        new OA\Property(property: 'published', type: 'object', nullable: true, description: 'The live override; null when customers see the default (or after a revert)'),
        new OA\Property(property: 'draft', type: 'object', nullable: true, description: 'The pending draft, visible only here'),
        new OA\Property(property: 'is_overridden', type: 'boolean'),
        new OA\Property(property: 'default_changed_since_override', type: 'boolean', description: 'The code default changed after the live override was published: review it'),
        new OA\Property(property: 'placeholders', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'required_placeholders', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'max_length', type: 'integer'),
        new OA\Property(property: 'read_only', type: 'boolean', description: 'Locked for the viewer: carries a code or link and the viewer lacks content.edit_secure'),
        new OA\Property(property: 'retired', type: 'boolean', description: 'The app no longer uses this key'),
        new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Help order customers see now'),
        new OA\Property(property: 'pending_position', type: 'integer', nullable: true, description: 'Help order waiting for Publish changes'),
    ],
)]
class AppTextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AppTextKey $k */
        $k = $this->resource;
        $live = $k->publishedVersion;
        $overridden = $live !== null && ! $live->is_default_marker;

        return [
            'key' => $k->text_key,
            'kind' => $k->kind,
            'area' => $k->area,
            'description' => $k->description,
            'default' => ['en' => $k->default_en, 'ar' => $k->default_ar],
            'published' => $overridden ? self::published($live) : null,
            'draft' => $k->draftVersion === null ? null : self::draft($k->draftVersion),
            'is_overridden' => $overridden,
            'default_changed_since_override' => $k->defaultChangedSinceOverride(),
            'placeholders' => $k->placeholders,
            'required_placeholders' => $k->required_placeholders,
            'max_length' => $k->max_length,
            // Locked for this viewer: a code or link template needs `content.edit_secure` (D7).
            'read_only' => $k->read_only && ! ($request->user()?->can(StaffPermission::CONTENT_EDIT_SECURE->value) ?? false),
            'retired' => $k->isRetired(),
            'position' => $k->position,
            'pending_position' => $k->pending_position,
        ];
    }

    /** @return array<string, mixed> */
    public static function published(AppTextVersion $v): array
    {
        return [
            'version' => $v->version,
            'en' => $v->text_en,
            'ar' => $v->text_ar,
            'is_hidden' => (bool) $v->is_hidden,
            'published_at' => $v->published_at?->toIso8601String(),
            'published_by' => $v->publisher === null ? null : ['id' => $v->publisher->staff_id, 'full_name' => $v->publisher->full_name],
            'bundle_version' => $v->bundle_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function draft(AppTextVersion $v): array
    {
        return [
            'version' => $v->version,
            'draft_version' => $v->draft_version,
            'en' => $v->text_en,
            'ar' => $v->text_ar,
            'is_default_marker' => $v->is_default_marker,
            'is_hidden' => $v->is_hidden,
            'edited_at' => $v->edited_at?->toIso8601String(),
            'edited_by' => $v->editor === null ? null : ['id' => $v->editor->staff_id, 'full_name' => $v->editor->full_name],
        ];
    }
}
