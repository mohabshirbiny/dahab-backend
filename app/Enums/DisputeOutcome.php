<?php

namespace App\Enums;

/** How a dispute ended (spec 014 FR-010, FR-013). `against_sale` only before payment. */
enum DisputeOutcome: string
{
    case RESUME = 'resume';
    case AGAINST_SALE = 'against_sale';
}
