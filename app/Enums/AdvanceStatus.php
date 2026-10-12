<?php

namespace App\Enums;

/** Life of a first-sale advance. */
enum AdvanceStatus: string
{
    case OUTSTANDING = 'outstanding';
    case RECOVERED = 'recovered';
    case WRITTEN_OFF = 'written_off';
}
