<?php

namespace App\Http\Requests\Dashboard\Pricing;

use App\Enums\AdjustmentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** A price to preview (both prices, a change, or nothing = current) and optional adjustments per karat. */
#[OA\Schema(
    schema: 'DashboardPreviewPrices',
    properties: [
        new OA\Property(property: 'bid_24k', type: 'string', example: '7944'),
        new OA\Property(property: 'ask_24k', type: 'string', example: '7990'),
        new OA\Property(property: 'change_pct', type: 'string', example: '-2.5'),
        new OA\Property(property: 'adjustments', type: 'object', description: 'Keyed by karat code: { buy?: Adjustment, sell?: Adjustment }', additionalProperties: new OA\AdditionalProperties(properties: [
            new OA\Property(property: 'buy', ref: '#/components/schemas/DashboardAdjustment'),
            new OA\Property(property: 'sell', ref: '#/components/schemas/DashboardAdjustment'),
        ])),
    ],
)]
class PreviewPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bid_24k' => ['nullable', 'required_with:ask_24k', 'prohibits:change_pct', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999'],
            'ask_24k' => ['nullable', 'required_with:bid_24k', 'numeric', 'decimal:0,4', 'gte:bid_24k', 'max:99999999'],
            'change_pct' => ['nullable', 'numeric', 'decimal:0,4', 'gt:-100', 'max:1000'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.*' => ['array:buy,sell'],
            'adjustments.*.*.kind' => ['required', Rule::enum(AdjustmentKind::class)],
            'adjustments.*.*.value' => ['required', 'numeric', 'decimal:0,4', 'between:-99999,99999'],
        ];
    }
}
