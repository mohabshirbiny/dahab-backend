<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(CustomerStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            // Customer file search (spec 007): a display reference or an E.164 phone.
            'q' => ['sometimes', 'string', 'min:1', 'max:32'],
        ];
    }

    /** The search term with spaces removed, or null when not searching. */
    public function search(): ?string
    {
        $q = preg_replace('/\s+/', '', (string) $this->validated('q', ''));

        return $q === '' ? null : $q;
    }

    public function status(): CustomerStatus
    {
        return CustomerStatus::from($this->validated('status', CustomerStatus::PENDING_VERIFICATION->value));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
