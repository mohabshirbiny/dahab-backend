<?php

namespace App\Http\Requests\Customer\Listing;

use App\Enums\PieceCategory;
use Illuminate\Validation\Rule;

/**
 * The per-category field rules shared by creating and editing a listing
 * (spec 010 data-model "Validation"; schema CHECKs listing_price_shape and
 * listing_amounts):
 *
 *   gold              karat, weight, making charge per gram; no asking price
 *   gold_with_diamond karat, weight, asking price; no making charge
 *   diamond           asking price; no karat, weight or making charge
 */
final class ListingRules
{
    /** Weight in grams: > 0, up to 9999.999, at most 3 decimals. */
    public const WEIGHT = ['numeric', 'decimal:0,3', 'gt:0', 'max:9999.999'];

    /** EGP a person types: at most 2 decimals. */
    public const MONEY = ['numeric', 'decimal:0,2', 'max:99999999.99'];

    /**
     * @param  bool  $partial  true for an edit: a field is only checked when it is sent
     * @return array<string, list<mixed>>
     */
    public static function for(PieceCategory $category, bool $partial): array
    {
        $gold = $category === PieceCategory::GOLD;
        $diamond = $category === PieceCategory::DIAMOND;
        $need = $partial ? 'sometimes' : 'required';

        return [
            'piece_type_id' => [$need, 'integer', Rule::exists('piece_type', 'piece_type_id')->where('category', $category->value)->where('is_enabled', true)],
            // A missing karat or weight on a non-diamond is refused earlier, as gold_needs_karat_weight.
            'karat_code' => $diamond ? ['prohibited'] : [$need, 'integer', Rule::exists('karat', 'karat_code')->where('is_enabled', true)],
            'stated_weight_g' => $diamond ? ['prohibited'] : [$need, ...self::WEIGHT],
            'making_charge_per_g' => $gold ? [$need, ...self::MONEY, 'gte:0'] : ['prohibited'],
            'asking_price' => $gold ? ['prohibited'] : [$need, ...self::MONEY, 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('dahab-listings.description_max')],
        ];
    }
}
