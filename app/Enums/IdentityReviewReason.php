<?php

namespace App\Enums;

/**
 * Structured reasons a reviewer can attach to an "Ask again" or "Reject"
 * decision on an identity document (docs Part 2 §10 — the Dashboard's
 * pill list). Stored as a JSONB array on `identity_document.review_reasons`
 * together with an optional free-text `review_note`.
 */
enum IdentityReviewReason: string
{
    case BLURRED_OR_GLARE = 'blurred_or_glare';
    case CARD_CUT_OFF = 'card_cut_off';
    case NAME_DOES_NOT_MATCH = 'name_does_not_match';
    case CARD_EXPIRED = 'card_expired';
    case BACK_MISSING = 'back_missing';
    case TEXT_NOT_READABLE = 'text_not_readable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
