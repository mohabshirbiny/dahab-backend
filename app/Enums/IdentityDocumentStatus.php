<?php

namespace App\Enums;

/**
 * `identity_document.status` (docs Part 2 §10).
 *
 * Transitions:
 *   pending             → verified | rejected | needs_resubmission
 *   needs_resubmission  → pending  (customer re-uploads and status resets)
 *
 * `VERIFIED` replaces the older `approved` label to align with the Dashboard
 * copy; the API contract now speaks in these values, and the migration folds
 * any legacy `approved` rows onto `verified` at rest.
 */
enum IdentityDocumentStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case NEEDS_RESUBMISSION = 'needs_resubmission';
    case REJECTED = 'rejected';

    public function isTerminal(): bool
    {
        return $this === self::VERIFIED || $this === self::REJECTED;
    }
}
