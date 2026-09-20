<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

/**
 * Step 1 of the six-step registration: the applicant's identity + credential.
 * Uniqueness is re-checked at submit (step 6) because another registration
 * can take the same phone while this one is in flight.
 */
#[OA\Schema(
    schema: 'StartRegistrationRequest',
    required: ['name', 'phone', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Mona Hassan Ibrahim'),
        new OA\Property(property: 'phone', type: 'string', example: '+201000000001', description: 'E.164'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
        new OA\Property(property: 'preferred_lang', type: 'string', enum: ['ar', 'en'], nullable: true, description: 'Defaults to ar'),
    ],
)]
class StartRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/', Rule::unique('customer', 'phone')],
            'password' => ['required', 'confirmed', 'string', Password::defaults()],
            'preferred_lang' => ['sometimes', Rule::in(['ar', 'en'])],
        ];
    }
}
