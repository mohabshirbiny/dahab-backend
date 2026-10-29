<?php

namespace App\Http\Requests\Dashboard\Finance;

use App\Enums\BankMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Record a bank movement outside the app (spec 015 FR-010). */
#[OA\Schema(
    schema: 'RecordBankMovementRequest',
    required: ['kind', 'direction', 'amount', 'occurred_on', 'reason'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['capital_in', 'operating_expense', 'bank_charge', 'profit_draw', 'own_transfer', 'supplier_refund', 'other']),
        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
        new OA\Property(property: 'amount', type: 'string', example: '14800', description: '> 0, at most 4 decimal places'),
        new OA\Property(property: 'occurred_on', type: 'string', format: 'date', description: 'The date on the bank statement; not in the future (Cairo). A closed day is accepted: the entry posts now.'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 500, description: 'What it was for'),
        new OA\Property(property: 'receiving_account_id', type: 'integer', nullable: true, description: 'Owner notes of 2026-10-29: the Dahab account (a receiving account, inactive allowed) the money came into or left. Required, except for an own-account transfer, where it is the account the money left and null means cash not yet assigned to an account'),
        new OA\Property(property: 'to_receiving_account_id', type: 'integer', nullable: true, description: 'Own-account transfer only (required there): the account the money reached; not the one it left'),
        new OA\Property(property: 'proof_upload_token', type: 'string', nullable: true, description: 'From POST /dashboard/uploads (purpose bank_movement_proof)'),
    ],
)]
class RecordBankMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(BankMovementKind::class)],
            'direction' => ['required', 'string', 'in:in,out'],
            'amount' => PayCompensationRequest::AMOUNT,
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Africa/Cairo')->toDateString()],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'proof_upload_token' => ['sometimes', 'nullable', 'string', 'max:100'],
            'receiving_account_id' => ['required_unless:kind,own_transfer', 'nullable', 'integer', 'exists:receiving_account,receiving_account_id'],
            'to_receiving_account_id' => ['required_if:kind,own_transfer', 'prohibited_unless:kind,own_transfer', 'nullable', 'integer',
                'exists:receiving_account,receiving_account_id', 'different:receiving_account_id'],
        ];
    }
}
