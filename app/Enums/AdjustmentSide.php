<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum AdjustmentSide: string
{
    case BUY = 'buy';
    case SELL = 'sell';
}
