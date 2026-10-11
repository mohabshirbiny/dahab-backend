<?php

namespace App\Http\Requests\Auth\Staff;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StaffDeviceApprovalStatusRequest',
    required: ['approval_ref'],
    description: 'Spec 021: the opaque ref from the held sign-in. Send it with the same `X-Device-Id`.',
    properties: [
        new OA\Property(property: 'approval_ref', type: 'string', maxLength: 128),
    ],
)]
class DeviceApprovalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approval_ref' => ['required', 'string', 'max:128'],
        ];
    }
}
