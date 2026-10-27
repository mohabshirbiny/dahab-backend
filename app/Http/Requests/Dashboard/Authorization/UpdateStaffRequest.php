<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * A staff member's details (docs/features/staff-edit-delete.md). Only the fields sent change; a
 * phone, once sent, cannot be emptied (the new-device code and the alerts go to it). Roles,
 * branch and founder status have their own endpoints or none. `reason` is enforced by
 * UpdateStaffAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'DashboardUpdateStaff',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'full_name', type: 'string', minLength: 2, maxLength: 120, example: 'Amr Salah'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amr@dahab.eg'),
        new OA\Property(property: 'phone', type: 'string', example: '+201000000009', description: 'E.164'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('staff', 'email')->whereNull('deleted_at')->ignore($this->route('staff'), 'staff_id')],
            'phone' => ['sometimes', 'required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
