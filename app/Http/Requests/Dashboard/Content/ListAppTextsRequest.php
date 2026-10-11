<?php

namespace App\Http\Requests\Dashboard\Content;

use App\Actions\Content\ListAppTextsAction;
use App\Models\AppTextKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Filters and paging of the App text list and its export (spec 019 US1). */
class ListAppTextsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'nullable', Rule::in([AppTextKey::KIND_APP, AppTextKey::KIND_FAQ, AppTextKey::KIND_NOTIFICATION])],
            'area' => ['sometimes', 'nullable', 'string', 'max:40'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changed' => ['sometimes', 'nullable', 'boolean'],
            'state' => ['sometimes', 'nullable', Rule::in(['draft', 'published', 'default'])],
            'include_retired' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    /** @return array{kind: ?string, area: ?string, q: ?string, changed: ?bool, state: ?string, include_retired: bool} */
    public function filters(): array
    {
        return [
            'kind' => $this->validated('kind'),
            'area' => $this->validated('area'),
            'q' => $this->validated('q'),
            'changed' => $this->has('changed') && $this->validated('changed') !== null ? $this->boolean('changed') : null,
            'state' => $this->validated('state'),
            'include_retired' => $this->boolean('include_retired'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }

    public function afterKey(): ?string
    {
        $cursor = $this->validated('cursor');
        if ($cursor === null || $cursor === '') {
            return null;
        }

        return ListAppTextsAction::decode($cursor)
            ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
    }
}
