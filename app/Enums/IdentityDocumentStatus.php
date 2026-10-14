<?php

namespace App\Enums;

/**
 * `identity_document.status` (docs Part 2 §10).
 *
 * Transitions:
 *   pending             → verified | rejected | needs_resubmission
 *   needs_resubmission  → verified | rejected (staff decide while waiting)
 *                       | superseded (the customer uploads a new attempt, a new row)
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
    /** An "Ask again" document answered by a newer attempt (testing fixes, item 2). Final. */
    case SUPERSEDED = 'superseded';

    public function isTerminal(): bool
    {
        return $this === self::VERIFIED || $this === self::REJECTED || $this === self::SUPERSEDED;
    }
}
