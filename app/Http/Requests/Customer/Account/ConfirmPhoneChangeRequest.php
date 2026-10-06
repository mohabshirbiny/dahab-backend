<?php

namespace App\Http\Requests\Customer\Account;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ConfirmPhoneChangeRequest',
    required: ['code'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: '123456', description: 'The code sent to the new number'),
    ],
)]
class ConfirmPhoneChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ];
    }
}
