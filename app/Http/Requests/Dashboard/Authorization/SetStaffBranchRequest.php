<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * `branch_id` null clears the branch. Only enabled branches can be chosen.
 * `reason` is enforced by SetStaffBranchAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'DashboardSetStaffBranch',
    required: ['branch_id', 'reason'],
    properties: [
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'An enabled branch, or null for none'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class SetStaffBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['present', 'nullable', 'integer', Rule::exists('branch', 'branch_id')->where('is_enabled', true)],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
