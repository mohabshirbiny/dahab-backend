<?php

namespace App\Http\Requests\Dashboard\Reference;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Code and purity are fixed once created: there is no update route. */
#[OA\Schema(
    schema: 'DashboardStoreKarat',
    required: ['code', 'purity'],
    properties: [
        new OA\Property(property: 'code', type: 'integer', minimum: 1, maximum: 24, example: 14),
        new OA\Property(property: 'purity', type: 'string', description: 'Decimal in (0, 1], at most 5 places', example: '0.585'),
        new OA\Property(property: 'sort_order', type: 'integer', minimum: 1, description: 'Default: last'),
    ],
)]
class StoreKaratRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'integer', 'between:1,24', 'unique:karat,karat_code'],
            'purity' => ['required', 'numeric', 'decimal:0,5', 'gt:0', 'lte:1'],
            'sort_order' => ['sometimes', 'integer', 'between:1,32767'],
        ];
    }
}
