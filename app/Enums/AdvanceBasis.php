<?php

namespace App\Enums;

/** What allowed an advance: a first-sale code, a staff enablement, or neither (the seller's first sale). */
enum AdvanceBasis: string
{
    case PROMO_CODE = 'promo_code';
    case STAFF_ENABLEMENT = 'staff_enablement';
    case AUTOMATIC = 'automatic';
}
