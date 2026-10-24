<?php

namespace App\Http\Requests\Dashboard\Customers;

use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Make a customer a market maker, or an ordinary customer again. Strings arrive trimmed. */
#[OA\Schema(
    schema: 'DashboardSetCustomerType',
    required: ['type', 'note'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['ordinary', 'market_maker']),
        new OA\Property(property: 'note', type: 'string', minLength: 1, maxLength: 1000, description: 'For the record'),
    ],
)]
class SetCustomerTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(CustomerType::class)],
            'note' => ['required', 'string', 'min:1', 'max:'.SuspendCustomerRequest::NOTE_MAX],
        ];
    }

    public function type(): CustomerType
    {
        return CustomerType::from((string) $this->validated('type'));
    }

    public function note(): string
    {
        return (string) $this->validated('note');
    }
}
