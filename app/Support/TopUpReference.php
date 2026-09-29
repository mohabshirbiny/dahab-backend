<?php

namespace App\Support;

use App\Models\Customer;

/**
 * A customer's personal top-up reference (spec 009 FR-005, research R5):
 * the fixed prefix `DAHAB-` plus their existing, unique display reference.
 * Nothing new is stored; a notice records the reference it showed.
 */
final class TopUpReference
{
    public const PREFIX = 'DAHAB-';

    public static function for(Customer $customer): string
    {
        return self::PREFIX.$customer->display_ref;
    }

    /**
     * Normalise a staff search term (FR-006): upper-case, spaces and dashes
     * removed, and a leading `DAHAB` dropped — so `dahab 004417`,
     * `DAHAB-004417` and `004417` all become `004417`.
     */
    public static function normalise(string $term): string
    {
        $term = strtoupper((string) preg_replace('/[\s\-]+/', '', $term));

        return str_starts_with($term, 'DAHAB') ? substr($term, 5) : $term;
    }
}
