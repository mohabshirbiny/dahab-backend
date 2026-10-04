<?php

namespace App\Http\Requests\Customer\Order;

use App\Enums\ExtensionRequestReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** The seller asks for more time to bring the piece (spec 014 FR-022): no time — staff choose it. */
#[OA\Schema(
    schema: 'RequestMoreTimeRequest',
    required: ['reason', 'detail'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['travelling', 'emergency', 'branch_closed', 'other']),
        new OA\Property(property: 'detail', type: 'string', minLength: 10, maxLength: 1000),
    ],
)]
class RequestMoreTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(ExtensionRequestReason::class)],
            'detail' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function reason(): ExtensionRequestReason
    {
        return ExtensionRequestReason::from((string) $this->validated('reason'));
    }
}
