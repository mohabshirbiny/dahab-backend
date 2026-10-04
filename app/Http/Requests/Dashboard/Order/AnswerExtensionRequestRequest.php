<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Staff accept (with 6, 12, 24 or 48 working hours) or refuse a request for more time (spec 014 FR-024). */
#[OA\Schema(
    schema: 'AcceptExtensionRequestRequest',
    required: ['hours', 'note'],
    properties: [
        new OA\Property(property: 'hours', type: 'integer', enum: [6, 12, 24, 48], description: 'Working hours added to the current reach-branch deadline at the branch of the order'),
        new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000, description: 'Note to both sides; the reason of the extension'),
    ],
)]
#[OA\Schema(
    schema: 'RefuseExtensionRequestRequest',
    required: ['note'],
    properties: [new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000, description: 'Sent to the seller')],
)]
class AnswerExtensionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accept = str_ends_with($this->path(), '/accept');

        return [
            'hours' => $accept ? ['required', 'integer', 'in:6,12,24,48'] : ['prohibited'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
