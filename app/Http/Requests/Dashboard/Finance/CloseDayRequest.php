<?php

namespace App\Http\Requests\Dashboard\Finance;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Close a day (spec 015 FR-014). */
#[OA\Schema(
    schema: 'CloseDayRequest',
    required: ['date', 'bank_balances'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', description: 'A Cairo day that has ended'),
        new OA\Property(property: 'bank_balances', type: 'array', description: 'The closing balance on that day\'s statement of every account the day asks for (GET daily-close banks: active, or holding money); each may be negative, at most 4 decimal places. Replaces bank_balance (2026-10-29).', items: new OA\Items(properties: [
            new OA\Property(property: 'receiving_account_id', type: 'integer'),
            new OA\Property(property: 'balance', type: 'string', example: '3142880'),
        ], type: 'object')),
        new OA\Property(property: 'explanation', type: 'string', minLength: 10, maxLength: 1000, nullable: true, description: 'Locks a day whose difference is not 0; without it such a day is saved unlocked'),
    ],
)]
class CloseDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'bank_balances' => ['present', 'array', 'max:100'],
            'bank_balances.*.receiving_account_id' => ['required', 'integer', 'distinct', 'exists:receiving_account,receiving_account_id'],
            'bank_balances.*.balance' => ['required', 'string', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
            'explanation' => ['sometimes', 'nullable', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<int, string> the typed balance by receiving account id */
    public function balances(): array
    {
        $out = [];
        foreach ($this->validated('bank_balances') as $line) {
            $out[(int) $line['receiving_account_id']] = (string) $line['balance'];
        }

        return $out;
    }
}
