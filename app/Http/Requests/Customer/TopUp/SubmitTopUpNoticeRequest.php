<?php

namespace App\Http\Requests\Customer\TopUp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SubmitTopUpNoticeRequest',
    required: ['amount', 'receiving_account_id'],
    properties: [
        new OA\Property(property: 'amount', type: 'string', example: '20000.00', description: 'EGP the customer sent; > 0, at most 2 decimals, at most 99,999,999.99'),
        new OA\Property(property: 'receiving_account_id', type: 'integer', description: 'The active Dahab account they sent to; it sets the method'),
        new OA\Property(property: 'receipt_upload_token', type: 'string', nullable: true, description: 'Optional: from POST /customer/me/uploads with purpose=topup_receipt; single use'),
    ],
)]
class SubmitTopUpNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'receiving_account_id' => ['required', 'integer', Rule::exists('receiving_account', 'receiving_account_id')->where('is_active', true)],
            'receipt_upload_token' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }
}
