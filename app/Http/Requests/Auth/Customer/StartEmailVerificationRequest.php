<?php

namespace App\Http\Requests\Auth\Customer;

use App\Enums\Governorate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Step 3: email + Egyptian governorate. Uniqueness on `customer.email` is
 * checked here and again at submit (steps can race).
 */
#[OA\Schema(
    schema: 'StartEmailVerificationRequest',
    required: ['registration_ref', 'email', 'governorate'],
    properties: [
        new OA\Property(property: 'registration_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'governorate', type: 'string', description: 'Governorate code from App\\Enums\\Governorate'),
    ],
)]
class StartEmailVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_ref' => ['required', 'string', 'uuid'],
            'email' => ['required', 'email:filter', Rule::unique('customer', 'email')],
            'governorate' => ['required', Rule::enum(Governorate::class)],
        ];
    }
}
