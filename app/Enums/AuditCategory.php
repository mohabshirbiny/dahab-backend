<?php

namespace App\Enums;

/**
 * The audit log viewer's categories (spec 006): the design's five chips plus
 * three for kinds the design does not place, and Listings (spec 010). "Everything" leaves out
 * sign-ins and sessions (Clarification 1).
 */
enum AuditCategory: string
{
    case MONEY = 'money';
    case PRICING = 'pricing';
    case ACCOUNTS = 'accounts';
    case IDENTITY = 'identity';
    case PROMO = 'promo';
    case REFERENCE = 'reference';
    case LISTINGS = 'listings';
    case SESSIONS = 'sessions';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::MONEY => 'Money',
            self::PRICING => 'Pricing',
            self::ACCOUNTS => 'Accounts',
            self::IDENTITY => 'Identity documents',
            self::PROMO => 'Promo codes',
            self::REFERENCE => 'Reference data',
            self::LISTINGS => 'Listings',
            self::SESSIONS => 'Sign-ins and sessions',
            self::SYSTEM => 'System',
        };
    }

    public function inEverything(): bool
    {
        return $this !== self::SESSIONS;
    }
}
