<?php

namespace App\Support\Withdrawals;

/**
 * The payout account number (spec 013 Clarifications): an Egyptian IBAN
 * (`EG` + 27 digits, ISO 13616 mod-97 = 1) or a plain account number of 8–20
 * digits. Spaces are removed and letters upper-cased before any check.
 */
final class Iban
{
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $value));
    }

    /** True for a valid Egyptian IBAN or an 8–20 digit account number (after normalize()). */
    public static function isAcceptable(string $normalized): bool
    {
        if (preg_match('/^[0-9]{8,20}$/', $normalized) === 1) {
            return true;
        }

        return self::isValidEgyptianIban($normalized);
    }

    public static function isValidEgyptianIban(string $normalized): bool
    {
        if (preg_match('/^EG[0-9]{27}$/', $normalized) !== 1) {
            return false;
        }

        // Move the country code and check digits to the end, turn letters into numbers (A=10 … Z=35).
        $rearranged = substr($normalized, 4).substr($normalized, 0, 4);
        $digits = preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), $rearranged);

        return bcmod((string) $digits, '97') === '1';
    }
}
