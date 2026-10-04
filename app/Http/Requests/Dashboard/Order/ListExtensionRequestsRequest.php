<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Enums\ExtensionRequestState;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** Sellers' requests for more time (spec 014 FR-024, FR-027). */
class ListExtensionRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', 'in:waiting,accepted,refused,lapsed,all'],
            'month' => ['sometimes', 'date_format:Y-m'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    /** @return list<ExtensionRequestState> */
    public function states(): array
    {
        $state = (string) $this->validated('state', 'waiting');

        return $state === 'all' ? ExtensionRequestState::cases() : [ExtensionRequestState::from($state)];
    }

    public function month(): ?string
    {
        return $this->validated('month');
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
