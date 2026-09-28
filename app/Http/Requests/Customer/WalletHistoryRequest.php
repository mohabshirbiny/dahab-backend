<?php

namespace App\Http\Requests\Customer;

use App\Support\Ledger\HistoryCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of the customer's own wallet history (spec 008 FR-013). */
class WalletHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function cursor(): ?HistoryCursor
    {
        return HistoryCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
