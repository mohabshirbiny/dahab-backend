<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')->where('guard_name', Staff::GUARD)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function role(): ?string
    {
        return $this->validated('role');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
