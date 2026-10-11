<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** "Forgot password?" on the Dashboard sign-in page: the address the link is sent to (spec 020). */
#[OA\Schema(
    schema: 'StaffResetRequestRequest',
    required: ['email'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'operations@dahab.test'),
    ],
)]
class StaffResetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
