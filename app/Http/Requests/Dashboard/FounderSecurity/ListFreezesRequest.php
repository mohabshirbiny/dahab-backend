<?php

namespace App\Http\Requests\Dashboard\FounderSecurity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFreezesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(['open', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return 'open'|'all' */
    public function state(): string
    {
        return $this->validated('state', 'open') === 'all' ? 'all' : 'open';
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
