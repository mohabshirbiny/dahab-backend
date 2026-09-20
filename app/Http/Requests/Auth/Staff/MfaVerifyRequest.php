<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffMfaVerifyRequest',
    required: ['session_ref'],
    description: 'Send either `code` (TOTP) or `recovery_code` (single use).',
    properties: [
        new OA\Property(property: 'session_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', pattern: '^[0-9]{6}$', example: '123456'),
        new OA\Property(property: 'recovery_code', type: 'string', example: 'abcde-fghij'),
    ],
)]
class MfaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_ref' => ['required', 'uuid'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }
}
