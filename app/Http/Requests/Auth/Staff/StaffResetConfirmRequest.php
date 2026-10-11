<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

/** The set-password page: the token from the emailed link and the new password (spec 020, D20-5). */
#[OA\Schema(
    schema: 'StaffResetConfirmRequest',
    required: ['token', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'token', type: 'string', description: 'The `token` query value of the emailed link'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
    ],
)]
class StaffResetConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
