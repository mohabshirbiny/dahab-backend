<?php

namespace App\Support\Pricing;

/**
 * EGP arithmetic on decimal strings (bcmath). Money is never a float
 * (spec 005). Intermediate results keep 8 decimals; published amounts are
 * rounded half-up (away from zero) to 4 — Part 3 §3.5.
 */
final class Money
{
    public const SCALE = 8;

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        return bcdiv($a, $b, self::SCALE);
    }

    /** `$pct` percent of `$amount`. */
    public static function percent(string $amount, string $pct): string
    {
        return self::div(self::mul($amount, $pct), '100');
    }

    public static function round4(string $value): string
    {
        $half = bccomp($value, '0', self::SCALE) < 0 ? '-0.00005' : '0.00005';

        // bcadd truncates toward zero at the target scale.
        return bcadd(bcadd($value, $half, self::SCALE), '0', 4);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    /** The 4-dp form of a value that is already exact (subtraction of 4-dp amounts). */
    public static function fixed4(string $value): string
    {
        return bcadd($value, '0', 4);
    }
}
