<?php

namespace App\Http\Requests\Identity;

use App\Enums\IdentityDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIdentityDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(IdentityDocumentStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function status(): IdentityDocumentStatus
    {
        return IdentityDocumentStatus::from($this->validated('status', IdentityDocumentStatus::PENDING->value));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
