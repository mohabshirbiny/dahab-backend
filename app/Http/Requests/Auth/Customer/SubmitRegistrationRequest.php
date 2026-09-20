<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Step 6: submit the completed registration. Only the ref is accepted; every
 * other field was validated on the step that owns it and has been held
 * server-side ever since, so the client cannot substitute a different name,
 * phone or password at the last moment.
 */
#[OA\Schema(
    schema: 'SubmitRegistrationRequest',
    required: ['registration_ref'],
    properties: [
        new OA\Property(property: 'registration_ref', type: 'string', format: 'uuid'),
    ],
)]
class SubmitRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_ref' => ['required', 'string', 'uuid'],
        ];
    }
}
