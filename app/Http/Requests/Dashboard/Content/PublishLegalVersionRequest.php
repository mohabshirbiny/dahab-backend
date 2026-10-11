<?php

namespace App\Http\Requests\Dashboard\Content;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardPublishLegalVersion',
    required: ['body_en', 'body_ar'],
    properties: [
        new OA\Property(property: 'body_en', type: 'string', description: 'The whole English text of the new version'),
        new OA\Property(property: 'body_ar', type: 'string', description: 'The whole Arabic text of the new version'),
        new OA\Property(property: 'is_material', type: 'boolean', description: 'Material: customers are asked to accept it again before they continue (terms and privacy)'),
    ],
)]
class PublishLegalVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Emptiness is `legal_body_required`'s business; the request only checks the shape.
        return [
            'body_en' => ['present', 'nullable', 'string', 'max:200000'],
            'body_ar' => ['present', 'nullable', 'string', 'max:200000'],
            'is_material' => ['sometimes', 'boolean'],
        ];
    }
}
