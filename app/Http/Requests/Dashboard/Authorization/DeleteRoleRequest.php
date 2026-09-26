<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** `reason` is required; DeleteRoleAction refuses without one (422 reason_required). */
#[OA\Schema(
    schema: 'DashboardDeleteRole',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class DeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
