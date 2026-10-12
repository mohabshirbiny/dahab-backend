<?php

namespace App\Enums;

/** The one price figure a seller sets on a piece (spec 022): it follows the category. */
enum ListingPriceField: string
{
    case MAKING_CHARGE_PER_G = 'making_charge_per_g';
    case ASKING_PRICE = 'asking_price';

    public static function for(PieceCategory $category): self
    {
        return $category === PieceCategory::GOLD ? self::MAKING_CHARGE_PER_G : self::ASKING_PRICE;
    }
}
