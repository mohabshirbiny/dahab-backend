<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginCustomerRequest',
    required: ['phone', 'password'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+201000000001'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
    ],
)]
class LoginCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
