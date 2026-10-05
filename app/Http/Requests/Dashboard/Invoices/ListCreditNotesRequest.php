<?php

namespace App\Http\Requests\Dashboard\Invoices;

use App\Support\Finance\Period;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** The staff Credit notes list (spec 016 FR-014). */
class ListCreditNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return Period::rules() + [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function after(): array
    {
        return [Period::after($this)];
    }

    /** @return array{0: string, 1: string} */
    public function period(): array
    {
        return Period::of($this, 30);
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
