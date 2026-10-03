<?php

namespace App\Http\Requests\Dashboard\Withdrawal;

use App\Enums\WithdrawalHoldReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HoldWithdrawalRequest',
    required: ['reason', 'message', 'note'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['name_mismatch', 'account_changed_recently', 'identity_pending', 'money_in_straight_out', 'other']),
        new OA\Property(property: 'message', type: 'string', minLength: 3, maxLength: 500, description: 'What the customer is told'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 1000, description: 'Staff only'),
    ],
)]
class HoldWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(WithdrawalHoldReason::class)],
            'message' => ['required', 'string', 'min:3', 'max:500'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): WithdrawalHoldReason
    {
        return WithdrawalHoldReason::from((string) $this->validated('reason'));
    }
}
