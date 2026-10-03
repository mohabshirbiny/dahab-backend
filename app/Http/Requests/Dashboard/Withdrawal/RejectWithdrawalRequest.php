<?php

namespace App\Http\Requests\Dashboard\Withdrawal;

use App\Enums\WithdrawalRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RejectWithdrawalRequest',
    required: ['reason', 'note'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['account_not_in_name', 'money_in_straight_out', 'identity_unconfirmed', 'customer_request', 'other'], description: 'The customer is told this reason'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 1000, description: 'Staff only'),
    ],
)]
class RejectWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(WithdrawalRejectReason::class)],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function reason(): WithdrawalRejectReason
    {
        return WithdrawalRejectReason::from((string) $this->validated('reason'));
    }
}
