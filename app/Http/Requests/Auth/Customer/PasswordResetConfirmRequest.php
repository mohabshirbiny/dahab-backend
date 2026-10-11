<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

/** Step 2 of "I forgot my password": the code and the new password (spec 020, D20-5). */
#[OA\Schema(
    schema: 'PasswordResetConfirmRequest',
    required: ['phone', 'code', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+201000000001', description: 'E.164, the number the code was sent to'),
        new OA\Property(property: 'code', type: 'string', example: '482913', description: 'The six-digit SMS code'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
    ],
)]
class PasswordResetConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $length = (int) config('dahab-auth.otp.code_length');

        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'code' => ['required', 'string', 'digits:'.$length],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
