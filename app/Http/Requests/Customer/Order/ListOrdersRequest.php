<?php

namespace App\Http\Requests\Customer\Order;

use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of the customer's own orders (spec 012 FR-001). */
class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'in:buyer,seller'],
            'group' => ['sometimes', 'in:open,closed'],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function role(): ?string
    {
        return $this->validated('role');
    }

    public function group(): ?string
    {
        return $this->validated('group');
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
