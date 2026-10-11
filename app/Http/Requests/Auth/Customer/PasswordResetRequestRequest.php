<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Step 1 of "I forgot my password": the phone the code goes to (spec 020). */
#[OA\Schema(
    schema: 'PasswordResetRequestRequest',
    required: ['phone'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+201000000001', description: 'E.164. The format is checked; whether the number is registered is never revealed.'),
    ],
)]
class PasswordResetRequestRequest extends FormRequest
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
