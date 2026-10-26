<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Release a staff sign-in held for a new device with the SMS code. */
#[OA\Schema(
    schema: 'StaffDeviceCodeVerifyRequest',
    required: ['challenge_id', 'code'],
    properties: [
        new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: '123456'),
    ],
)]
class DeviceCodeVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $length = (int) config('dahab-auth.otp.code_length');

        return [
            'challenge_id' => ['required', 'string', 'uuid'],
            'code' => ['required', 'string', 'digits:'.$length],
        ];
    }
}
