<?php

namespace App\Enums;

/** Which waiver a settlement applied, stored on the order (research R17). */
enum WaiverKind: string
{
    case NONE = 'none';
    case FREE_RELIST = 'free_relist';
    case MARKET_MAKER = 'market_maker';
    case CREDIT = 'credit';
}
