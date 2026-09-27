<?php

namespace App\Enums;

/** Spec 005 pricing vocabulary (data-model). */
enum PriceSource: string
{
    case FEED = 'feed';
    case MANUAL = 'manual';
}
