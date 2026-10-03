<?php

namespace App\Http\Requests\Dashboard\Payout;

use App\Enums\PayoutRefusalReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RefusePayoutAccountRequest',
    required: ['reason', 'note'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['name_mismatch', 'name_shortened', 'not_in_customer_name', 'details_invalid', 'other'], description: 'The customer is told this reason'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 1000, description: 'Staff only'),
    ],
)]
class RefusePayoutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(PayoutRefusalReason::class)],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): PayoutRefusalReason
    {
        return PayoutRefusalReason::from((string) $this->validated('reason'));
    }
}
