<?php

namespace App\Http\Requests\Customer\Account;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RequestPhoneChangeRequest',
    required: ['phone'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+201000000002', description: 'E.164, the registration format'),
    ],
)]
class RequestPhoneChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ];
    }
}
