<?php

namespace App\Enums;

/** How often one person may use a code (per national ID, research R7). */
enum PromoUseLimit: string
{
    case UNLIMITED = 'unlimited';
    case ONCE = 'once';
    case MONTHLY = 'monthly';
}
