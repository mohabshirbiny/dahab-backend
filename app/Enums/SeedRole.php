<?php

namespace App\Enums;

/**
 * The six roles a fresh install starts with (spec 002 FR-070). Roles are
 * Dashboard-managed data; this enum is only the seed/test vocabulary used by
 * SyncPermissionCatalogueAction, seeders, factories and tests.
 *
 * Never use it for an authorization decision: check permission codes
 * (StaffPermission) or the staff flags (is_founder, is_system) instead.
 */
enum SeedRole: string
{
    case CEO = 'ceo';
    case COO = 'coo';
    case FINANCE = 'finance';
    case OPERATIONS = 'operations';
    case VERIFICATION = 'verification';
    case IGI_BRANCH = 'igi_branch';

    public function displayName(): string
    {
        return match ($this) {
            self::CEO => 'CEO',
            self::COO => 'COO',
            self::FINANCE => 'Finance',
            self::OPERATIONS => 'Operations',
            self::VERIFICATION => 'Verification',
            self::IGI_BRANCH => 'IGI Branch',
        };
    }

    /** Seed value of the role's "requires MFA" flag (today's ceo/coo/finance rule). */
    public function seedRequiresMfa(): bool
    {
        return in_array($this, [self::CEO, self::COO, self::FINANCE], true);
    }
}
