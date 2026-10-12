<?php

namespace App\Enums;

/** How a commission credit lowers a commission (research R2). */
enum CreditKind: string
{
    case WAIVE = 'waive';
    case PERCENT = 'percent';
    case AMOUNT = 'amount';
}
