<?php

namespace App\Enums;

/**
 * The permission catalogue (spec 002 FR-001): one code per protected
 * Dashboard action. Codes are defined by the product and cannot be created
 * or deleted from the Dashboard, because a code nothing checks protects
 * nothing. Which roles hold which code is Dashboard-managed data;
 * SyncPermissionCatalogueAction keeps the `permissions` table in step with
 * this enum and applies `seedRoles()` only when a code first appears.
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

    /** List staff members and their roles (spec 002 FR-020). */
    case STAFF_VIEW = 'staff.view';

    /** Manage roles, their permissions, and staff role assignment (spec 002 FR-003). */
    case ROLES_MANAGE = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_VIEW => 'View customers and verification details',
            self::CUSTOMER_SUSPEND => 'Suspend or reinstate a customer',
            self::IDENTITY_VIEW => 'Open identity documents',
            self::IDENTITY_REVIEW => 'Approve or reject identity documents',
            self::STAFF_VIEW => 'View staff members and their roles',
            self::ROLES_MANAGE => 'Manage roles, their permissions, and staff role assignment',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::CUSTOMER_VIEW, self::CUSTOMER_SUSPEND => 'Customers',
            self::IDENTITY_VIEW, self::IDENTITY_REVIEW => 'Identity',
            self::STAFF_VIEW, self::ROLES_MANAGE => 'Access control',
        };
    }

    /** Branch-scoped codes only authorize records of the staff member's own branch (spec 002 FR-041). */
    public function isBranchScoped(): bool
    {
        return false;
    }

    /**
     * Roles (besides `ceo`, which gets every code) that receive this code when
     * it first appears in the catalogue. Seed only — never re-applied, so
     * Dashboard edits survive deploys.
     *
     * Wallet-touching permissions added by later modules MUST NOT list `coo`
     * here: COO has everything except wallets by default, and that stays
     * editable from the Dashboard (spec 002 FR-051).
     *
     * @return list<string>
     */
    public function seedRoles(): array
    {
        return match ($this) {
            // Both founders may suspend/reinstate (docs part1 §4.3, corrected reading).
            self::CUSTOMER_SUSPEND => [SeedRole::COO->value],
            self::IDENTITY_VIEW,
            self::IDENTITY_REVIEW,
            self::CUSTOMER_VIEW => [SeedRole::VERIFICATION->value],
            // Both founders may change permissions (docs part1 §4.3 corrected reading).
            self::STAFF_VIEW,
            self::ROLES_MANAGE => [SeedRole::COO->value],
        };
    }
}
