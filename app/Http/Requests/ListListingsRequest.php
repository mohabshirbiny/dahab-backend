<?php

namespace App\Http\Requests;

use App\Enums\ListingState;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A page of listings by state, for the seller's own list and the staff
 * review queue (spec 010 FR-018, FR-027). Keyset: `cursor` is the previous
 * page's `meta.next_cursor`.
 */
class ListListingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::enum(ListingState::class)],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function state(): ?ListingState
    {
        return ListingState::tryFrom((string) $this->validated('state', ''));
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(int $default): int
    {
        return (int) $this->validated('per_page', $default);
    }
}
