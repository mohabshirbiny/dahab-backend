<?php

namespace App\Enums;

/**
 * Sanctum token abilities. Every token carries exactly one of these, so an
 * endpoint that requires `*:access` can never be reached with a refresh token
 * and vice versa, and a customer ability is never a staff ability.
 *
 * Routes enforce them with `abilities:customer:access` etc. (after `auth:<guard>`).
 */
enum TokenAbility: string
{
    case CUSTOMER_ACCESS = 'customer:access';
    case CUSTOMER_REFRESH = 'customer:refresh';
    case STAFF_ACCESS = 'staff:access';
    case STAFF_REFRESH = 'staff:refresh';

    /** @param 'customer'|'staff' $actorKind */
    public static function access(string $actorKind): self
    {
        return match ($actorKind) {
            'customer' => self::CUSTOMER_ACCESS,
            'staff' => self::STAFF_ACCESS,
        };
    }

    /** @param 'customer'|'staff' $actorKind */
    public static function refresh(string $actorKind): self
    {
        return match ($actorKind) {
            'customer' => self::CUSTOMER_REFRESH,
            'staff' => self::STAFF_REFRESH,
        };
    }
}
