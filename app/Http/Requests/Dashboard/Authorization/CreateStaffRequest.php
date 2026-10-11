<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * A new staff member (spec 020 US3). No password is sent or stored: the member chooses one
 * through the emailed link. Anything else in the body (`is_founder`, `password`) is ignored.
 * `reason` is enforced by CreateStaffAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'DashboardCreateStaff',
    required: ['full_name', 'email', 'roles', 'reason'],
    properties: [
        new OA\Property(property: 'full_name', type: 'string', minLength: 2, maxLength: 120, example: 'Amr Salah'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'amr@dahab.eg'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+201000000009', description: 'E.164, optional'),
        new OA\Property(property: 'roles', type: 'array', minItems: 1, items: new OA\Items(type: 'string'), description: 'Existing role machine names'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'An enabled branch, optional'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class CreateStaffRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('staff', 'email')],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', Staff::GUARD)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branch', 'branch_id')->where('is_enabled', true)],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
