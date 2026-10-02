<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** The branch work list (spec 012 FR-010). `branch_id` only narrows it for staff with no branch. */
class WorkListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
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
