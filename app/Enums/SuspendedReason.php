<?php

namespace App\Enums;

/**
 * Why staff suspended a customer — the fixed list of the Dashboard design
 * (spec 007 FR-005, Part 1 §4.3). The database checks the same codes
 * (`customer_suspended_reason_check`). The customer-facing wording lives in
 * the Customer App; staff see `label()`.
 */
enum SuspendedReason: string
{
    case PIECE_MISREPRESENTED = 'piece_misrepresented';
    case OFF_PLATFORM_DEALING = 'off_platform_dealing';
    case REPEATED_DISPUTES = 'repeated_disputes';
    case REPORTED_BY_USERS = 'reported_by_users';
    case IDENTITY_UNCONFIRMED = 'identity_unconfirmed';
    case CUSTOMER_REQUEST = 'customer_request';
    case OTHER = 'other';

    /** Spec 012: set only by the system when a seller's cancellations reach the threshold. */
    case REPEATED_CANCELLATIONS = 'repeated_cancellations';

    public function label(): string
    {
        return match ($this) {
            self::PIECE_MISREPRESENTED => 'A piece was not what they said it was',
            self::OFF_PLATFORM_DEALING => 'Tried to deal outside Dahab',
            self::REPEATED_DISPUTES => 'Repeated disputes against them',
            self::REPORTED_BY_USERS => 'Reported by other users',
            self::IDENTITY_UNCONFIRMED => 'Identity could not be confirmed',
            self::CUSTOMER_REQUEST => 'They asked us to close it',
            self::OTHER => 'Something else',
            self::REPEATED_CANCELLATIONS => 'Cancelled too many accepted sales',
        };
    }

    /** The seven reasons staff choose from (spec 007); `repeated_cancellations` is set by the system only. */
    public static function staffChoices(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r !== self::REPEATED_CANCELLATIONS));
    }
}
