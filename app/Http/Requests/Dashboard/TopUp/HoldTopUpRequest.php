<?php

namespace App\Http\Requests\Dashboard\TopUp;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardHoldTopUp',
    required: ['note'],
    properties: [
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, description: 'Why it is on hold; staff only'),
    ],
)]
class HoldTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }
}
