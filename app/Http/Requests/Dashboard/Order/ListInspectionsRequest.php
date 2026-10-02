<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Enums\InspectionOutcome;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A page of the inspection results (spec 012 FR-022). Dates are Cairo calendar days. */
class ListInspectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer'],
            'outcome' => ['sometimes', Rule::enum(InspectionOutcome::class)],
            'cursor' => ['sometimes', 'string', 'max:300'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function branchId(): ?int
    {
        $id = $this->validated('branch_id');

        return $id === null ? null : (int) $id;
    }

    public function outcome(): ?InspectionOutcome
    {
        return InspectionOutcome::tryFrom((string) $this->validated('outcome', ''));
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
