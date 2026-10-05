<?php

namespace App\Support\Invoices;

/**
 * Dahab's legal identity for tax documents (spec 016 FR-008), read from
 * config/dahab-invoices.php. Complete only when all six details are filled;
 * an incomplete configuration never blocks a payment — the document waits.
 */
final class IssuerDetails
{
    public const KEYS = ['legal_name_en', 'legal_name_ar', 'address_en', 'address_ar', 'tax_registration_no', 'commercial_register_no'];

    public function complete(): bool
    {
        return $this->snapshot() !== null;
    }

    /** @return array<string, string>|null the six details, or null while any is missing */
    public function snapshot(): ?array
    {
        $issuer = (array) config('dahab-invoices.issuer', []);
        $out = [];
        foreach (self::KEYS as $key) {
            $value = trim((string) ($issuer[$key] ?? ''));
            if ($value === '') {
                return null;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
