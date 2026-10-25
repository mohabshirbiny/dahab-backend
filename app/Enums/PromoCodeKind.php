<?php

namespace App\Enums;

/**
 * What a promo code is for (spec 023 R9): a dealer code, a first-sale (advance) code, a seller-side
 * commission discount, or a buyer's code by product (gold: the seller's price; stones: a lower deposit).
 */
enum PromoCodeKind: string
{
    case MARKET_MAKER = 'market_maker';
    case FIRST_SALE = 'first_sale';
    case DISCOUNT = 'discount';
    case BUYER = 'buyer';
}
