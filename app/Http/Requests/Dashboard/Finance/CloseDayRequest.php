<?php

namespace App\Http\Requests\Dashboard\Finance;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Close a day (spec 015 FR-014). */
#[OA\Schema(
    schema: 'CloseDayRequest',
    required: ['date', 'bank_balance'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', description: 'A Cairo day that has ended'),
        new OA\Property(property: 'bank_balance', type: 'string', example: '3142880', description: 'The closing balance across all of Dahab\'s accounts on that day\'s statements; may be negative, at most 4 decimal places'),
        new OA\Property(property: 'explanation', type: 'string', minLength: 10, maxLength: 1000, nullable: true, description: 'Locks a day whose difference is not 0; without it such a day is saved unlocked'),
    ],
)]
class CloseDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'bank_balance' => ['required', 'string', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
            'explanation' => ['sometimes', 'nullable', 'string', 'min:10', 'max:1000'],
        ];
    }
}
