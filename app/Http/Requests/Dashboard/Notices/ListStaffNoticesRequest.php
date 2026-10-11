<?php

namespace App\Http\Requests\Dashboard\Notices;

use Illuminate\Foundation\Http\FormRequest;

class ListStaffNoticesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
