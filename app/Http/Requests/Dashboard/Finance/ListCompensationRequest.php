<?php

namespace App\Http\Requests\Dashboard\Finance;

use App\Enums\CompensationReason;
use App\Support\Finance\CompensationListQuery;
use App\Support\Finance\Period;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The Compensation list and its export (spec 015 FR-001, FR-003). */
class ListCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return Period::rules() + [
            'reason' => ['sometimes', 'string', Rule::enum(CompensationReason::class)],
            'paid_by' => ['sometimes', 'uuid'],
            'customer_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function after(): array
    {
        return [Period::after($this)];
    }

    public function listQuery(): CompensationListQuery
    {
        [$from, $to] = Period::of($this, 30);

        return new CompensationListQuery($from, $to, CompensationReason::tryFrom((string) $this->validated('reason')),
            $this->validated('paid_by'), $this->validated('customer_id'));
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
