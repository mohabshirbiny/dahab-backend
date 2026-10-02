<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of the staff Buy requests list (spec 012 FR-023). */
class ListBuyRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'in:queued,accepted,ended'],
            'listing_id' => ['sometimes', 'uuid'],
            'near_expiry' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function state(): string
    {
        return (string) $this->validated('state', 'queued');
    }

    public function listingId(): ?string
    {
        return $this->validated('listing_id');
    }

    public function nearExpiry(): bool
    {
        return $this->boolean('near_expiry');
    }

    public function branchId(): ?int
    {
        $id = $this->validated('branch_id');

        return $id === null ? null : (int) $id;
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
