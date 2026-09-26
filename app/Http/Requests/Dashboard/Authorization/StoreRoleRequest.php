<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Enums\StaffPermission;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStoreRole',
    required: ['name', 'display_name', 'permissions'],
    properties: [
        new OA\Property(property: 'name', type: 'string', pattern: '^[a-z][a-z0-9_]{2,49}$', description: 'Unique machine name, immutable'),
        new OA\Property(property: 'display_name', type: 'string', maxLength: 100),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'requires_mfa', type: 'boolean', default: false),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: 'Catalogue codes the actor holds; may be empty'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500, nullable: true),
    ],
)]
class StoreRoleRequest extends FormRequest
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,49}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:'.self::NAME_PATTERN, Rule::unique('roles', 'name')->where('guard_name', Staff::GUARD)],
            'display_name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_mfa' => ['sometimes', 'boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(StaffPermission::class)],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
