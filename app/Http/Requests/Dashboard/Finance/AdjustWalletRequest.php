<?php

namespace App\Http\Requests\Dashboard\Finance;

use App\Enums\AdjustmentDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Adjust a customer's wallet (spec 015 FR-006). */
#[OA\Schema(
    schema: 'AdjustWalletRequest',
    required: ['direction', 'amount', 'reason'],
    properties: [
        new OA\Property(property: 'direction', type: 'string', enum: ['credit', 'debit']),
        new OA\Property(property: 'amount', type: 'string', example: '500', description: '> 0, at most 4 decimal places'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000, description: 'Recorded on the entry and the audit row; never sent to the customer'),
    ],
)]
class AdjustWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::enum(AdjustmentDirection::class)],
            'amount' => PayCompensationRequest::AMOUNT,
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
