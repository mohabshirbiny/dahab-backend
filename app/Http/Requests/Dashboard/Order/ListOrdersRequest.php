<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Actions\Orders\Staff\ListOrdersAction;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of the staff Orders list (spec 012 FR-022). */
class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['sometimes', 'in:'.implode(',', ListOrdersAction::GROUPS)],
            'past_deadline' => ['sometimes', 'boolean'],
            // Spec 018: the state of the buyer's free-relist offer.
            'free_relist' => ['sometimes', 'in:open,used,expired'],
            'branch_id' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function group(): string
    {
        // A free-relist filter looks at collected (closed) orders: without a group it means all of them.
        return (string) $this->validated('group', $this->freeRelist() === null ? 'open' : 'all');
    }

    public function freeRelist(): ?string
    {
        $value = $this->validated('free_relist');

        return $value === null ? null : (string) $value;
    }

    public function pastDeadline(): bool
    {
        return $this->boolean('past_deadline');
    }

    public function branchId(): ?int
    {
        $id = $this->validated('branch_id');

        return $id === null ? null : (int) $id;
    }

    public function search(): ?string
    {
        return $this->validated('q');
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
