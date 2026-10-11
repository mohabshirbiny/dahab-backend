<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffFrozenConfirmUnfreezeRequest',
    required: ['email', 'password'],
    description: 'Spec 021: the frozen founder\'s first unfreeze confirmation. Send either `code` (TOTP) or `recovery_code` (single use), with the `X-Device-Id` of a device already known for this founder.',
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
        new OA\Property(property: 'code', type: 'string', pattern: '^[0-9]{6}$', example: '123456'),
        new OA\Property(property: 'recovery_code', type: 'string', example: 'abcde-fghij'),
        new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
    ],
)]
class FrozenConfirmUnfreezeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
