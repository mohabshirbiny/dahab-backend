<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** The code a customer shows at the counter (spec 012 FR-019, FR-020, research R10). */
#[OA\Schema(
    schema: 'HandoverRequest',
    required: ['code'],
    properties: [new OA\Property(property: 'code', type: 'string', pattern: '^[0-9]{6}$', example: '482913')],
)]
class HandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
