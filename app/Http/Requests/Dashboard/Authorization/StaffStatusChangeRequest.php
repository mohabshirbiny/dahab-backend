<?php

namespace App\Http\Requests\Dashboard\Authorization;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Disable or enable a staff member (spec 020 US3, C4): a written reason, enforced by the Action. */
#[OA\Schema(
    schema: 'DashboardStaffStatusChange',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500, example: 'Left the company'),
    ],
)]
class StaffStatusChangeRequest extends FormRequest
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
