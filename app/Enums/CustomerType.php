<?php

namespace App\Enums;

/**
 * `customer.customer_type`. A market maker is a dealer who buys pieces that
 * have not sold (docs Part 3 §market maker); everyone else is `ordinary`.
 */
enum CustomerType: string
{
    case ORDINARY = 'ordinary';
    case MARKET_MAKER = 'market_maker';
}
