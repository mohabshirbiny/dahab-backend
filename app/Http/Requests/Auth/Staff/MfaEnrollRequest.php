<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffMfaEnrollRequest',
    required: ['session_ref', 'code'],
    properties: [
        new OA\Property(property: 'session_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', pattern: '^[0-9]{6}$', example: '123456', description: 'The current code from the authenticator app that scanned `otpauth_url`.'),
    ],
)]
class MfaEnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_ref' => ['required', 'uuid'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
