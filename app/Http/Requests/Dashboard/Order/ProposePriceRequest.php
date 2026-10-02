<?php

namespace App\Http\Requests\Dashboard\Order;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** The new price after a stone regrade (spec 012 research R11). */
#[OA\Schema(
    schema: 'ProposePriceRequest',
    required: ['price', 'reason'],
    properties: [
        new OA\Property(property: 'price', type: 'string', example: '108000.0000', description: 'EGP, up to 4 decimals, > 0'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000),
    ],
)]
class ProposePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999999'],
            'reason' => ['required', 'string', 'between:10,1000'],
        ];
    }
}
