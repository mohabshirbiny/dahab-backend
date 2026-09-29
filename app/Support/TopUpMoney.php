<?php

namespace App\Support;

/**
 * Money strings for top-ups (spec 009). Never floats.
 */
final class TopUpMoney
{
    /** 4 places, as everywhere in the API (docs/platform/api-contract.md "Money"). */
    public static function format(string|int|null $amount): ?string
    {
        return $amount === null ? null : bcadd((string) $amount, '0', 4);
    }

    /** For people: thousands separators and 2 places, e.g. "19,900.00". */
    public static function display(string $amount): string
    {
        [$whole, $fraction] = explode('.', bcadd($amount, '0', 2));

        return strrev(implode(',', str_split(strrev($whole), 3))).'.'.$fraction;
    }
}
