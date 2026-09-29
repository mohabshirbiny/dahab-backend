<?php

namespace App\Enums;

/** Whether a top-up started as the customer's notice or was credited by hand by staff (spec 009). */
enum TopUpOrigin: string
{
    case NOTICE = 'notice';
    case BY_HAND = 'by_hand';

    public function label(): string
    {
        return match ($this) {
            self::NOTICE => 'Matched from a notice',
            self::BY_HAND => 'Credited by hand',
        };
    }
}
