<?php

namespace App\Http\Requests\Dashboard\Content;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSaveAppTextDraft',
    required: ['text_en', 'text_ar'],
    properties: [
        new OA\Property(property: 'text_en', type: 'string', description: 'Required in both languages; refused empty (content_language_missing)'),
        new OA\Property(property: 'text_ar', type: 'string'),
        new OA\Property(property: 'draft_version', type: 'integer', minimum: 1, nullable: true, description: 'The draft_version of the copy being edited; a different current draft is refused (content_draft_changed)'),
        new OA\Property(property: 'is_hidden', type: 'boolean', description: 'FAQ entries only'),
    ],
)]
class SaveAppTextDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Emptiness and length are the content rules' business (their own codes), so the
        // request only checks the shape.
        return [
            'text_en' => ['present', 'nullable', 'string', 'max:20000'],
            'text_ar' => ['present', 'nullable', 'string', 'max:20000'],
            'draft_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_hidden' => ['sometimes', 'boolean'],
        ];
    }
}
