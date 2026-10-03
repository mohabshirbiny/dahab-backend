<?php

namespace App\Http\Requests\Dashboard\Payout;

use App\Enums\PayoutAccountState;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Payout accounts to check (spec 013 FR-003). */
class ListPayoutAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::enum(PayoutAccountState::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function state(): PayoutAccountState
    {
        return PayoutAccountState::tryFrom((string) $this->validated('state', '')) ?? PayoutAccountState::PENDING_REVIEW;
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
