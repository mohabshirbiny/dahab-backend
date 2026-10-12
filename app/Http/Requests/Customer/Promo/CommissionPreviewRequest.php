<?php

namespace App\Http\Requests\Customer\Promo;

use App\Enums\PieceCategory;
use App\Http\Requests\Customer\Listing\ListingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The piece figures of the sell form and an optional code (spec 023 contracts/customer-sell-api.md §1).
 * The figures follow the listing rules (gold: karat, weight, making charge; diamond: asking price;
 * gold with diamond: karat, weight, asking price).
 */
class CommissionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = PieceCategory::tryFrom((string) $this->input('category'));
        $gold = $category === PieceCategory::GOLD;
        $diamond = $category === PieceCategory::DIAMOND;

        return [
            'category' => ['required', Rule::enum(PieceCategory::class)],
            'karat' => $diamond ? ['prohibited'] : ['required', 'integer', Rule::exists('karat', 'karat_code')->where('is_enabled', true)],
            'weight_g' => $diamond ? ['prohibited'] : ['required', ...ListingRules::WEIGHT],
            'making_charge_per_g' => $gold ? ['required', ...ListingRules::MONEY, 'gte:0'] : ['prohibited'],
            'asking_price' => $gold ? ['prohibited'] : ['required', ...ListingRules::MONEY, 'gt:0'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
