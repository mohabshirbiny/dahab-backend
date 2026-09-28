<?php

namespace App\Http\Requests\Dashboard\Customers;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Reinstate a suspended customer (spec 007 FR-007). Strings arrive trimmed. */
#[OA\Schema(
    schema: 'DashboardReinstateCustomer',
    required: ['note'],
    properties: [
        new OA\Property(property: 'note', type: 'string', minLength: 1, maxLength: 1000, description: 'For the record'),
    ],
)]
class ReinstateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:1', 'max:'.SuspendCustomerRequest::NOTE_MAX],
        ];
    }

    public function note(): string
    {
        return (string) $this->validated('note');
    }
}
