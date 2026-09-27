<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum AdjustmentKind: string
{
    case FIXED = 'fixed';
    case PERCENT = 'percent';
}
