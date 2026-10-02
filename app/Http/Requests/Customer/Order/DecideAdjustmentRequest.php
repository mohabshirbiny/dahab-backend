<?php

namespace App\Http\Requests\Customer\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** The buyer's answer to an adjusted price (spec 012 FR-013). */
#[OA\Schema(
    schema: 'DecideAdjustmentRequest',
    required: ['accept', 'inspection_id'],
    properties: [
        new OA\Property(property: 'accept', type: 'boolean'),
        new OA\Property(property: 'inspection_id', type: 'string', format: 'uuid', description: 'The result being answered (inspection.inspection_id of the order); a newer correction makes it stale'),
    ],
)]
class DecideAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accept' => ['required', 'boolean'],
            'inspection_id' => ['required', 'uuid'],
        ];
    }
}
