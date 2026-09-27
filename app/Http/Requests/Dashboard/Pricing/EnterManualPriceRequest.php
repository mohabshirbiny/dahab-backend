<?php

namespace App\Http\Requests\Dashboard\Pricing;

use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Both 24K prices, or a percentage change from the current pair. `reason` is
 * enforced by EnterManualPriceAction (→ 422 reason_required).
 */
#[OA\Schema(
    schema: 'DashboardEnterManualPrice',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'bid_24k', type: 'string', description: 'EGP per gram of 24K, > 0, ≤ 4 decimals; with ask_24k', example: '7944.0000'),
        new OA\Property(property: 'ask_24k', type: 'string', description: '≥ bid_24k', example: '7990.0000'),
        new OA\Property(property: 'change_pct', type: 'string', description: 'Instead of the two prices: percent change from the current pair (> −100)', example: '-2.5'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class EnterManualPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bid_24k' => ['required_without:change_pct', 'prohibits:change_pct', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999'],
            'ask_24k' => ['required_with:bid_24k', 'numeric', 'decimal:0,4', 'gte:bid_24k', 'max:99999999'],
            'change_pct' => ['required_without:bid_24k', 'numeric', 'decimal:0,4', 'gt:-100', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX],
        ];
    }
}
