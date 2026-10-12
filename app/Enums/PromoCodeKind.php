<?php

namespace App\Enums;

/** What a promo code is for (spec 023 R9): a dealer code, a first-sale (advance) code, or a seller-side commission discount. */
enum PromoCodeKind: string
{
    case MARKET_MAKER = 'market_maker';
    case FIRST_SALE = 'first_sale';
    case DISCOUNT = 'discount';
}
