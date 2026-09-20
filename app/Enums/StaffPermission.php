<?php

namespace App\Enums;

/**
 * Dashboard permission codes seeded into Spatie's `permissions` table
 * (guard: staff). This enum is the one place that says which roles hold
 * which permission; DashboardRolesAndPermissionsSeeder reads it.
 *
 * Only permissions required by the current auth/dashboard spec live here.
 * Adding one is an enum + seeder change, never a runtime write (spec A-06).
 */
enum StaffPermission: string
{
    /** Suspend or reinstate a customer account (spec FR-S-011, docs part1 §4.3). */
    case CUSTOMER_SUSPEND = 'customer.suspend';

    /** Open an identity document's image and its review metadata; every open is logged (docs part1 §5.4). */
    case IDENTITY_VIEW = 'identity.view';

    /** Approve or reject an ID / passport (docs part2 §10, "Approve an ID or passport"). */
    case IDENTITY_REVIEW = 'identity.review';

    /** Browse the "Users and Verification" list and open a customer's verification details. */
    case CUSTOMER_VIEW = 'customer.view';

    /**
     * @return list<StaffRole>
     */
    public function roles(): array
    {
        return match ($this) {
            // Both founders may suspend/reinstate (docs part1 §4.3, corrected reading).
            self::CUSTOMER_SUSPEND => [StaffRole::CEO, StaffRole::COO],
            self::IDENTITY_VIEW,
            self::IDENTITY_REVIEW,
            self::CUSTOMER_VIEW => [StaffRole::CEO, StaffRole::VERIFICATION],
        };
    }
}
