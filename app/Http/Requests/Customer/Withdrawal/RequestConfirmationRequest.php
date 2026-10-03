<?php

namespace App\Http\Requests\Customer\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RequestWithdrawalConfirmationRequest',
    required: ['amount', 'payout_account_id'],
    properties: [
        new OA\Property(property: 'amount', type: 'string', example: '42000.00', description: 'EGP, > 0, at most 2 decimals, at most the available balance'),
        new OA\Property(property: 'payout_account_id', type: 'string', format: 'uuid', description: 'The verified account in use'),
    ],
)]
class RequestConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'payout_account_id' => ['required', 'uuid'],
        ];
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }
}
