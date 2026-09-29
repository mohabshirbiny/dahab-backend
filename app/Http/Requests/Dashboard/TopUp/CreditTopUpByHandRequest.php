<?php

namespace App\Http\Requests\Dashboard\TopUp;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardCreditTopUpByHand',
    required: ['customer_id', 'amount', 'receiving_account_id', 'note'],
    properties: [
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'amount', type: 'string', example: '15000.00', description: 'What actually arrived; > 0, at most 2 decimals'),
        new OA\Property(property: 'receiving_account_id', type: 'integer', description: 'The Dahab account it reached (inactive allowed); it sets the method'),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, description: 'How you identified the customer'),
        new OA\Property(property: 'arrival_reference', type: 'string', nullable: true, maxLength: 100, description: 'The provider\'s transaction reference; required when the customer is suspended'),
    ],
)]
class CreditTopUpByHandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customer,customer_id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'receiving_account_id' => ['required', 'integer', 'exists:receiving_account,receiving_account_id'],
            'note' => ['required', 'string', 'max:1000'],
            'arrival_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
