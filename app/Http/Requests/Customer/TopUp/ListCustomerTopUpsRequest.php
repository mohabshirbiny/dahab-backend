<?php

namespace App\Http\Requests\Customer\TopUp;

use App\Enums\TopUpStatus;
use App\Support\TopUpCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A page of the customer's own top-ups (spec 009 FR-012). */
class ListCustomerTopUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TopUpStatus::class)],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function status(): ?TopUpStatus
    {
        return TopUpStatus::tryFrom((string) $this->validated('status', ''));
    }

    public function cursor(): ?TopUpCursor
    {
        return TopUpCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
