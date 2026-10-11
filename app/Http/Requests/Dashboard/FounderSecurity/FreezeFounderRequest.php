<?php

namespace App\Http\Requests\Dashboard\FounderSecurity;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** The reason is required (`reason_required` from the Action, like every access change). */
#[OA\Schema(
    schema: 'DashboardFreezeFounder',
    required: ['staff_id', 'reason'],
    properties: [
        new OA\Property(property: 'staff_id', type: 'string', format: 'uuid', description: 'The other founder'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 1000, example: 'Lost phone, possible compromise'),
    ],
)]
class FreezeFounderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
