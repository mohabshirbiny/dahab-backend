<?php

namespace App\Enums;

/** What a code does to the commission (data-model 1.1). */
enum CommissionMode: string
{
    case WAIVE = 'waive';
    case PERCENT = 'percent';
    case NONE = 'none';
}
