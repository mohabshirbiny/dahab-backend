<?php

namespace App\Http\Requests\Dashboard\Reference;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardBranchHour',
    description: 'One open interval. dow: 0 = Sunday … 6 = Saturday. Several per day are allowed (split day).',
    required: ['dow', 'opens_at', 'closes_at'],
    properties: [
        new OA\Property(property: 'dow', type: 'integer', minimum: 0, maximum: 6, example: 0),
        new OA\Property(property: 'opens_at', type: 'string', pattern: '^\d{2}:\d{2}$', example: '10:00'),
        new OA\Property(property: 'closes_at', type: 'string', pattern: '^\d{2}:\d{2}$', example: '18:00'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardStoreBranch',
    required: ['name_en', 'name_ar', 'address_en', 'address_ar', 'hours'],
    properties: [
        new OA\Property(property: 'name_en', type: 'string', maxLength: 150),
        new OA\Property(property: 'name_ar', type: 'string', maxLength: 150),
        new OA\Property(property: 'address_en', type: 'string', maxLength: 500),
        new OA\Property(property: 'address_ar', type: 'string', maxLength: 500),
        new OA\Property(property: 'contact_phone', type: 'string', maxLength: 30, nullable: true, description: 'The branch\'s phone, shown to customers with the address and hours; null clears it'),
        new OA\Property(property: 'map_url', type: 'string', format: 'uri', maxLength: 500, nullable: true, description: 'A map link shown to customers; null clears it'),
        new OA\Property(property: 'timezone', type: 'string', description: 'IANA name; default Africa/Cairo', example: 'Africa/Cairo'),
        new OA\Property(property: 'is_enabled', type: 'boolean', description: 'Default true'),
        new OA\Property(property: 'hours', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranchHour'), description: 'The whole week; may be empty (never open)'),
    ],
)]
class StoreBranchRequest extends FormRequest
{
    use ValidatesBranchWeek;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'address_en' => ['required', 'string', 'max:500'],
            'address_ar' => ['required', 'string', 'max:500'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'map_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'is_enabled' => ['sometimes', 'boolean'],
            ...$this->weekRules(required: true),
        ];
    }

    public function after(): array
    {
        return $this->weekAfter();
    }
}
