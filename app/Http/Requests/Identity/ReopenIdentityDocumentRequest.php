<?php

namespace App\Http\Requests\Identity;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * "Move back to waiting" on a rejected customer: why staff reopen the check
 * (for example, the customer called customer service). `reason` is also
 * enforced by ReopenIdentityDocumentAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'ReopenIdentityDocumentRequest',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500, example: 'The customer called customer service with a valid ID.'),
    ],
)]
class ReopenIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.ReasonRule::MIN, 'max:'.ReasonRule::MAX],
        ];
    }
}
