<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Enums\StaffPermission;
use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * `name` is not accepted: the machine name is immutable. `reason` is
 * enforced by UpdateRoleAction (→ 422 reason_required) when the permission
 * set or the MFA flag actually changes.
 */
#[OA\Schema(
    schema: 'DashboardUpdateRole',
    minProperties: 1,
    properties: [
        new OA\Property(property: 'display_name', type: 'string', maxLength: 100),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'requires_mfa', type: 'boolean'),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: 'Replaces the whole set'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500, description: 'Required when permissions or requires_mfa change'),
    ],
)]
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'requires_mfa' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(StaffPermission::class)],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
