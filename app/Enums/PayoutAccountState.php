<?php

namespace App\Enums;

/**
 * Where a payout account stands (`payout_account_state`, spec 013). Mirrors
 * `payout_account_transition`; the database guard (SQLSTATE DH008) is the
 * backstop. `refused` and `removed` are final.
 */
enum PayoutAccountState: string
{
    case PENDING_REVIEW = 'pending_review';
    case ACTIVE = 'active';
    case REFUSED = 'refused';
    case REMOVING = 'removing';
    case REMOVED = 'removed';

    public function canMoveTo(self $to): bool
    {
        return match ($this) {
            self::PENDING_REVIEW => in_array($to, [self::ACTIVE, self::REFUSED, self::REMOVED], true),
            self::ACTIVE => in_array($to, [self::REMOVING, self::REMOVED], true),
            self::REMOVING => in_array($to, [self::ACTIVE, self::REMOVED], true),
            self::REFUSED, self::REMOVED => false,
        };
    }

    /** May receive a release: a removing account still gets its in-flight withdrawals (analysis I1). */
    public function canReceiveRelease(): bool
    {
        return $this === self::ACTIVE || $this === self::REMOVING;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING_REVIEW => 'Waiting for the name check',
            self::ACTIVE => 'Verified',
            self::REFUSED => 'Refused',
            self::REMOVING => 'Being removed',
            self::REMOVED => 'Removed',
        };
    }
}
