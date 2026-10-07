<?php

namespace App\Enums;

/**
 * `customer.status` — the authoritative lifecycle column for a customer
 * account. `is_verified` and `is_suspended` are kept as legacy/derived flags
 * that any transition through this enum keeps consistent (docs Part 1 §4.2).
 *
 * Transitions:
 *   pending_verification → active | rejected         (identity review)
 *   any other state      → suspended                  (Customer::suspend(), spec 007)
 *   suspended            → the state it interrupted   (Customer::reinstate())
 *   any state            → closed                     (the customer closes it, spec 017; final)
 *
 * While suspended, `is_verified` describes the interrupted state (true only
 * when it was `active`), so the SUSPENDED entry in legacyFlags() is not used
 * by suspend(); `customer_status_flags_consistent` allows either value.
 *
 * A `needs_resubmission` document keeps the customer at `pending_verification`.
 */
enum CustomerStatus: string
{
    case PENDING_VERIFICATION = 'pending_verification';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case SUSPENDED = 'suspended';
    case CLOSED = 'closed';

    /** @return array{is_verified: bool, is_suspended: bool} */
    public function legacyFlags(): array
    {
        return match ($this) {
            self::PENDING_VERIFICATION,
            self::REJECTED => ['is_verified' => false, 'is_suspended' => false],
            self::ACTIVE => ['is_verified' => true,  'is_suspended' => false],
            self::SUSPENDED => ['is_verified' => true,  'is_suspended' => true],
            // Closing keeps the flags of the state it ended (spec 017); never applied by a transition.
            self::CLOSED => ['is_verified' => false, 'is_suspended' => false],
        };
    }

    public function canLogin(): bool
    {
        return $this === self::ACTIVE;
    }
}
