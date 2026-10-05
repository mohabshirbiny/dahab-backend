<?php

namespace App\Http\Requests\Customer\Invoices;

use App\Enums\PartyRole;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The customer's own invoices (spec 016 FR-015): sold, bought or both. */
class ListOwnInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::enum(PartyRole::class)],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function role(): ?PartyRole
    {
        return PartyRole::tryFrom((string) $this->validated('role'));
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
