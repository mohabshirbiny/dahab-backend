<?php

namespace App\Http\Requests\Dashboard\Audit;

use App\Enums\AuditCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the audit log list and export (spec 006 FR-003). */
class AuditFilterRequest extends FormRequest
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
            'category' => ['sometimes', Rule::enum(AuditCategory::class)],
            'actor' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                if ($value !== 'system' && ! preg_match('/^[0-9a-f-]{36}$/i', (string) $value)) {
                    $fail('The actor must be a staff id or "system".');
                }
            }],
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:50'],
            'entity_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
