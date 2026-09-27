<?php

namespace App\Http\Requests\Dashboard\Pricing;

use App\Enums\AdjustmentKind;
use App\Support\Authorization\ReasonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardAdjustment',
    required: ['kind', 'value'],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['fixed', 'percent'], description: 'fixed = EGP per gram of the karat, added; percent = × (1 + value/100)'),
        new OA\Property(property: 'value', type: 'string', example: '-15.0000'),
    ],
)]
#[OA\Schema(
    schema: 'DashboardUpdateKaratAdjustments',
    required: ['buy', 'sell', 'reason'],
    properties: [
        new OA\Property(property: 'buy', ref: '#/components/schemas/DashboardAdjustment', description: 'Sellers get: applied to the market bid'),
        new OA\Property(property: 'sell', ref: '#/components/schemas/DashboardAdjustment', description: 'Buyers pay: applied to the market ask'),
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 500),
    ],
)]
class UpdateKaratAdjustmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $side = fn (string $name) => [
            "{$name}" => ['required', 'array:kind,value'],
            "{$name}.kind" => ['required', Rule::enum(AdjustmentKind::class)],
            "{$name}.value" => ['required', 'numeric', 'decimal:0,4', 'between:-99999,99999'],
        ];

        return [...$side('buy'), ...$side('sell'), 'reason' => ['nullable', 'string', 'max:'.ReasonRule::MAX]];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['buy', 'sell'] as $name) {
                if ($this->input("{$name}.kind") === AdjustmentKind::PERCENT->value
                    && is_numeric($this->input("{$name}.value")) && (float) $this->input("{$name}.value") <= -100) {
                    $validator->errors()->add("{$name}.value", 'A percentage must be above −100.');
                }
            }
        }];
    }
}
