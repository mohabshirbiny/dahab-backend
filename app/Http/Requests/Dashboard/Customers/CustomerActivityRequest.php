<?php

namespace App\Http\Requests\Dashboard\Customers;

use App\Support\Audit\AuditCursor;
use Illuminate\Foundation\Http\FormRequest;

/** A page of a customer file's History or Sessions (spec 007 US3). */
class CustomerActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function cursor(): ?AuditCursor
    {
        return AuditCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
