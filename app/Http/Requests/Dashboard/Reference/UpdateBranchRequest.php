<?php

namespace App\Http\Requests\Dashboard\Reference;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Any subset of the fields; `hours`, when present, replaces the whole week. */
#[OA\Schema(
    schema: 'DashboardUpdateBranch',
    minProperties: 1,
    properties: [
        new OA\Property(property: 'name_en', type: 'string', maxLength: 150),
        new OA\Property(property: 'name_ar', type: 'string', maxLength: 150),
        new OA\Property(property: 'address_en', type: 'string', maxLength: 500),
        new OA\Property(property: 'address_ar', type: 'string', maxLength: 500),
        new OA\Property(property: 'timezone', type: 'string', description: 'IANA name'),
        new OA\Property(property: 'is_enabled', type: 'boolean', description: 'false disables the branch; branches are never deleted'),
        new OA\Property(property: 'hours', type: 'array', items: new OA\Items(ref: '#/components/schemas/DashboardBranchHour'), description: 'Replaces the whole week'),
    ],
)]
class UpdateBranchRequest extends FormRequest
{
    use ValidatesBranchWeek;

    private const FIELDS = ['name_en', 'name_ar', 'address_en', 'address_ar', 'timezone', 'is_enabled', 'hours'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'address_en' => ['sometimes', 'required', 'string', 'max:500'],
            'address_ar' => ['sometimes', 'required', 'string', 'max:500'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'is_enabled' => ['sometimes', 'boolean'],
            ...$this->weekRules(required: false),
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (array_intersect_key($this->all(), array_flip(self::FIELDS)) === []) {
                    $validator->errors()->add('body', 'Send at least one field to change.');
                }
            },
            ...$this->weekAfter(),
        ];
    }
}
