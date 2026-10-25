<?php

namespace App\Http\Requests\Dashboard\Promo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Dashboard's New-code form (spec 023 contracts/staff-promo-api.md §2). Everything but
 * `code` is optional so the spec 019 body keeps working; the Action decides what the terms mean
 * together (kind inference, the dealer rules).
 */
class CreatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{3,32}$/'],
            'kind' => ['sometimes', Rule::in(['market_maker', 'first_sale', 'discount', 'buyer'])],
            'side' => ['sometimes', Rule::in(['seller', 'buyer'])],
            'product_type' => ['sometimes', 'nullable', Rule::in(['gold', 'diamond', 'gold_with_diamond'])],
            'commission_amount_egp' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'deposit_percent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'lt:100', 'decimal:0,4'],
            'deposit_amount_egp' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'],
            'audience' => ['sometimes', Rule::in(['account', 'anyone'])],
            'tied_to' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tied_customer_id' => ['sometimes', 'nullable', 'uuid'],
            'use_limit' => ['sometimes', Rule::in(['unlimited', 'once', 'monthly'])],
            'commission_mode' => ['sometimes', Rule::in(['waive', 'percent', 'amount', 'none'])],
            'commission_percent' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'lt:100', 'decimal:0,4'],
            'gives_spread' => ['sometimes', 'boolean'],
            'min_listed_days' => ['sometimes', 'nullable', 'integer', 'between:0,3650'],
            'requires_approval' => ['sometimes', 'boolean'],
            'monthly_cap_egp' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'first_sale_only' => ['sometimes', 'boolean'],
            'pays_advance' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> only what was sent, in the Action's shape */
    public function terms(): array
    {
        return $this->validated();
    }
}
