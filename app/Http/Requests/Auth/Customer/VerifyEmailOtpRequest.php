<?php

namespace App\Http\Requests\Auth\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Step 4: prove control of the email address. */
#[OA\Schema(
    schema: 'VerifyEmailOtpRequest',
    required: ['registration_ref', 'otp'],
    properties: [
        new OA\Property(property: 'registration_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'otp', type: 'string', example: '123456'),
    ],
)]
class VerifyEmailOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $length = (int) config('dahab-auth.otp.code_length');

        return [
            'registration_ref' => ['required', 'string', 'uuid'],
            'otp' => ['required', 'string', 'digits:'.$length],
        ];
    }
}
