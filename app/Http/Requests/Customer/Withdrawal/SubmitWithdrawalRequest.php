<?php

namespace App\Http\Requests\Customer\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SubmitWithdrawalRequest',
    required: ['confirmation_id', 'amount', 'payout_account_id'],
    properties: [
        new OA\Property(property: 'confirmation_id', type: 'string', format: 'uuid', description: 'The confirmed email confirmation (spec 013 R5; replaces Part 2\'s email_confirmation_token)'),
        new OA\Property(property: 'amount', type: 'string', example: '42000.00', description: 'Must equal the confirmed amount'),
        new OA\Property(property: 'payout_account_id', type: 'string', format: 'uuid', description: 'Must equal the confirmed account'),
    ],
)]
class SubmitWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmation_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'payout_account_id' => ['required', 'uuid'],
        ];
    }
}
