<?php

namespace App\Http\Requests\Customer\BuyRequest;

use App\Enums\BuyRequestState;
use App\Support\BuyRequests\BuyRequestCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A page of the buyer's own requests (spec 011 FR-009). */
class ListBuyRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::enum(BuyRequestState::class)],
            'listing_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function state(): ?BuyRequestState
    {
        return BuyRequestState::tryFrom((string) $this->validated('state', ''));
    }

    public function listingId(): ?string
    {
        return $this->validated('listing_id');
    }

    public function cursor(): ?BuyRequestCursor
    {
        return BuyRequestCursor::decode($this->validated('cursor'));
    }

    public function perPage(int $default): int
    {
        return (int) $this->validated('per_page', $default);
    }
}
