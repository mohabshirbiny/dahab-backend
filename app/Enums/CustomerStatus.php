<?php

namespace App\Enums;

/**
 * `customer.status` — the authoritative lifecycle column for a customer
 * account. `is_verified` and `is_suspended` are kept as legacy/derived flags
 * that any transition through this enum keeps consistent (docs Part 1 §4.2).
 *
 * Transitions (all through the identity Actions):
 *   pending_verification → active | rejected
 *   active               → suspended
 *   suspended            → active
 *
 * A `needs_resubmission` document keeps the customer at `pending_verification`.
 */
enum CustomerStatus: string
{
    case PENDING_VERIFICATION = 'pending_verification';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case SUSPENDED = 'suspended';

    /** @return array{is_verified: bool, is_suspended: bool} */
    public function legacyFlags(): array
    {
        return match ($this) {
            self::PENDING_VERIFICATION,
            self::REJECTED => ['is_verified' => false, 'is_suspended' => false],
            self::ACTIVE => ['is_verified' => true,  'is_suspended' => false],
            self::SUSPENDED => ['is_verified' => true,  'is_suspended' => true],
        };
    }

    public function canLogin(): bool
    {
        return $this === self::ACTIVE;
    }
}
