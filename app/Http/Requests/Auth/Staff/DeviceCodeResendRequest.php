<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Ask for a fresh SMS code on a staff sign-in held for a new device. */
#[OA\Schema(
    schema: 'StaffDeviceCodeResendRequest',
    required: ['challenge_id'],
    properties: [
        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
    ],
)]
class DeviceCodeResendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'uuid'],
        ];
    }
}
