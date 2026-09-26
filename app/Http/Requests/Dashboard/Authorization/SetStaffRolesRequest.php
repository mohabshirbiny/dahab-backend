<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Only `roles` and `reason` are read: anything else in the body (for example
 * `is_founder`) is ignored (spec 002 FR-043). `reason` is enforced by
 * SetStaffRolesAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'DashboardSetStaffRoles',
    required: ['roles', 'reason'],
    properties: [
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Role machine names; replaces the whole set; may be empty'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class SetStaffRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', Staff::GUARD)],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
