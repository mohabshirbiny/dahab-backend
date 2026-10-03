<?php

namespace App\Http\Requests\Dashboard\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReleaseWithdrawalRequest',
    required: ['bank_txn_number'],
    properties: [
        new OA\Property(property: 'bank_txn_number', type: 'string', minLength: 3, maxLength: 64, description: 'The bank transaction number of the transfer sent at Dahab\'s bank'),
        new OA\Property(property: 'transfer_reference', type: 'string', maxLength: 64, nullable: true, example: 'WD-12'),
        new OA\Property(property: 'value_date', type: 'string', format: 'date', nullable: true, description: 'Not in the future'),
    ],
)]
class ReleaseWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_txn_number' => ['required', 'string', 'min:3', 'max:64'],
            'transfer_reference' => ['nullable', 'string', 'max:64'],
            'value_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
