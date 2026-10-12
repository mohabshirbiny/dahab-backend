<?php

namespace App\Enums;

/** What allowed an advance: a first-sale code or a staff enablement. */
enum AdvanceBasis: string
{
    case PROMO_CODE = 'promo_code';
    case STAFF_ENABLEMENT = 'staff_enablement';
}
