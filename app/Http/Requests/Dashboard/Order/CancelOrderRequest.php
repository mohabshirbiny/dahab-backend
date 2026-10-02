<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Staff cancel an acceptance (spec 011 FR-020a, research R22). */
#[OA\Schema(
    schema: 'CancelOrderRequest',
    required: ['reason', 'relist'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000, example: 'The seller reported the piece damaged before delivery.', description: 'Audited; buyer and seller are told'),
        new OA\Property(property: 'relist', type: 'boolean', description: 'true: the piece goes back on the market (accepted → live); false: it is withdrawn for good (accepted → withdrawn)'),
    ],
)]
class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.config('dahab-buy-requests.cancel_reason_min'), 'max:'.config('dahab-buy-requests.cancel_reason_max')],
            'relist' => ['required', 'boolean'],
        ];
    }
}
