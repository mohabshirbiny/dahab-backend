<?php

namespace App\Http\Requests\Dashboard\TopUp;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardMatchTopUp',
    required: ['amount', 'receiving_account_id'],
    properties: [
        new OA\Property(property: 'amount', type: 'string', example: '19900.00', description: 'What actually arrived; > 0, at most 2 decimals'),
        new OA\Property(property: 'receiving_account_id', type: 'integer', description: 'The Dahab account it reached; must have the notice\'s method (inactive allowed)'),
        new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 1000, description: 'Required when the amount differs from the claim'),
        new OA\Property(property: 'arrival_reference', type: 'string', nullable: true, maxLength: 100, description: 'The provider\'s transaction reference; required when the customer is suspended'),
    ],
)]
class MatchTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'receiving_account_id' => ['required', 'integer', 'exists:receiving_account,receiving_account_id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'arrival_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
