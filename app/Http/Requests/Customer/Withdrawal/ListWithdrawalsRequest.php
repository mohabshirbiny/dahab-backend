<?php

namespace App\Http\Requests\Customer\Withdrawal;

use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of the customer's own withdrawals (spec 013). */
class ListWithdrawalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'in:open,closed'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function group(): ?string
    {
        return $this->validated('state');
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
