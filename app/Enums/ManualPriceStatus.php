<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum ManualPriceStatus: string
{
    case PENDING = 'pending';
    case EFFECTIVE = 'effective';
    case SUPERSEDED = 'superseded';
    case LAPSED = 'lapsed';
}
