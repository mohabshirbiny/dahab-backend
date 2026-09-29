<?php

namespace App\Http\Requests\Dashboard\TopUp;

use App\Enums\TopUpRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardRejectTopUp',
    required: ['reason', 'note'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['money_not_received', 'duplicate_notice', 'sender_not_accepted', 'other'], description: 'Told to the customer'),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, description: 'Staff only; never shown to the customer'),
    ],
)]
class RejectTopUpRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(TopUpRejectReason::class)],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): TopUpRejectReason
    {
        return TopUpRejectReason::from($this->validated('reason'));
    }
}
