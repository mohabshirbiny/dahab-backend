<?php

namespace App\Http\Requests\Dashboard\Pricing;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Type and range depend on the key and are checked by ChangeSettingAction. */
#[OA\Schema(
    schema: 'DashboardUpdateSetting',
    required: ['value', 'reason'],
    properties: [
        new OA\Property(property: 'value', description: 'A decimal string (≤ 4 decimals) for numeric keys, a boolean for flags', oneOf: [
            new OA\Schema(type: 'string', example: '18'),
            new OA\Schema(type: 'boolean'),
        ]),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['present'],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
