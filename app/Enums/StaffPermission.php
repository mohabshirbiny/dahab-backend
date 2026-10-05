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

    /** See the Karats and Branches screens (spec 004). */
    case REFERENCE_VIEW = 'reference.view';

    /** Turn a karat on or off (Part 2 §10 "Turn a karat on or off"). */
    case KARATS_TOGGLE = 'karats.toggle';

    /** Add a karat — not in the Part 1 matrix, so founders only (spec 004). */
    case KARATS_CREATE = 'karats.create';

    /** Add or edit an inspection branch, its hours and closures (Part 1 §4.3). */
    case BRANCHES_MANAGE = 'branches.manage';

    /** See gold prices, adjustments and settings (spec 005). */
    case PRICING_VIEW = 'pricing.view';

    /** Change commission, VAT, per-karat adjustments and the price rules (Part 1 §4.2 "Change commission or spread rates", "Set the gold price correction"). */
    case PRICING_RATES_MANAGE = 'pricing.rates.manage';

    /** Change deadlines, deposits and thresholds — not in the Part 1 matrix, so founders (spec 005). */
    case SETTINGS_MANAGE = 'settings.manage';

    /** Enter a gold price by hand while the feed is down (Part 1 §4.2 "Enter a gold price manually"). */
    case GOLD_PRICE_ENTER = 'gold_price.enter';

    /** Confirm a manual gold price above the deviation threshold (spec 005 Clarifications). */
    case GOLD_PRICE_CONFIRM = 'gold_price.confirm';

    /** See the whole audit log (Part 1 §4.3 "View the audit log": CEO). */
    case AUDIT_VIEW_ALL = 'audit.view_all';

    /** See your own actions in the audit log (Part 1 §4.3: COO, Finance, Operations, Verification). */
    case AUDIT_VIEW_OWN = 'audit.view_own';

    /** See wallet balances, statements and the safety figure (Part 1 §4.2 "View a wallet balance", "Open a wallet statement"; spec 008). */
    case WALLET_VIEW = 'wallet.view';

    /** Incoming transfers: list, receipt, match, hold, reject and credit by hand (Part 1 §4.2 "Match an incoming transfer"; spec 009). */
    case TOPUP_MATCH = 'topup.match';

    /** Add, edit and deactivate Dahab's receiving accounts (spec 009). */
    case TOPUP_ACCOUNTS_MANAGE = 'topup.accounts.manage';

    /** See the review queue and a listing; approve or reject it (Part 1 §4.1 "Approve or reject a new listing"; spec 010). */
    case LISTING_REVIEW = 'listing.review';

    /** Send a listing back to its seller with a message (Part 1 §4.1 "Ask a seller for a better photo"; spec 010). */
    case LISTING_REQUEST_CHANGES = 'listing.request_changes';

    /** Take a live listing off the market (Part 1 §4.1 "Take a live listing down"; spec 010). */
    case LISTING_TAKEDOWN = 'listing.takedown';

    /** Any of these opens the review queue, a listing and its media (spec 010 research R10). */
    public const LISTING_ANY = 'listing.review|listing.request_changes|listing.takedown';

    /** Cancel an order (Part 1 §4.1 "Cancel an order"); spec 011: cancel an acceptance, refunding the buyer. */
    case ORDER_CANCEL = 'order.cancel';

    /** See the Orders page and an order (spec 012; amounts on orders, not a wallet). */
    case ORDER_VIEW = 'order.view';

    /** Mark a piece received at the branch (spec 012; branch-scoped by the staff member's assigned branch). */
    case ORDER_RECEIVE = 'order.receive';

    /** Enter an inspection result (Part 1 §4.1 "Enter an inspection result"; spec 012). */
    case INSPECTION_ENTER = 'inspection.enter';

    /** Propose the new price after a stone regrade (spec 012; follows "Cancel an order"). */
    case ORDER_PRICE_ADJUST = 'order.price_adjust';

    /** Change the inspection branch on an open order (Part 1 §4.1; spec 012). */
    case ORDER_CHANGE_BRANCH = 'order.change_branch';

    /** Extend a deadline on request (Part 1 §4.1; spec 012). */
    case ORDER_EXTEND_DEADLINE = 'order.extend_deadline';

    /** Confirm handover at the counter (Part 1 §4.1; spec 012). */
    case ORDER_HANDOVER = 'order.handover';

    /** See every buy request across listings (product-owner decision 2026-10-01; spec 012). */
    case BUY_REQUEST_VIEW = 'buy_request.view';

    /** Release a withdrawal (Part 1 §4.2) — spec 013: the queue, take for review, hold, reject, release, export. Never the COO. */
    case WITHDRAWAL_RELEASE = 'withdrawal.release';

    /** Verify a payout bank account against the ID (Part 1 §4.3; spec 013). */
    case PAYOUT_ACCOUNT_VERIFY = 'payout_account.verify';

    /** Handle disputes and freeze orders (Part 1 §4.1 "Freeze an order during a dispute"; spec 014). */
    case DISPUTE_HANDLE = 'dispute.handle';

    /** Refund a buyer in full (Part 1 §4.2) — spec 014: resolve a dispute against the sale. Never the COO. */
    case ORDER_REFUND = 'order.refund';

    /** Pay compensation to a wallet, up to the caps (Part 1 §4.2; spec 014). Never the COO. */
    case COMPENSATION_PAY = 'compensation.pay';

    /** Pay compensation above the caps (Part 1 §4.2 "CEO unlimited"; spec 014 R7). Seeded to no role. */
    case COMPENSATION_UNCAPPED = 'compensation.uncapped';

    /** Adjust a wallet balance directly (Part 1 §4.2 "CEO only"; spec 015). Seeded to no role. */
    case WALLET_ADJUST = 'wallet.adjust';

    /** Record a bank movement outside the app (Part 1 §4.2; spec 015). Never the COO. */
    case BANK_RECORD = 'bank.record';

    /** Close the day (Part 1 §4.2; spec 015). Never the COO. */
    case DAY_CLOSE = 'day.close';

    /** Read tax invoices, credit notes, their PDFs and the export (spec 016 Clarifications). Never the COO. */
    case INVOICE_VIEW = 'invoice.view';

    /** Issue or correct a tax invoice — a credit note (Part 1 §4.2; spec 016). Never the COO. */
    case INVOICE_CORRECT = 'invoice.correct';

    /** Any of these opens the inspection work list (spec 012 research R18). */
    public const WORK_LIST_ANY = 'inspection.enter|order.receive|order.handover';

    /** Any of these opens the inspection results (spec 012 research R18). */
    public const INSPECTIONS_ANY = 'inspection.enter|order.view';

    /** Either of these reads the withdrawals list and a withdrawal (spec 013 FR-012); only withdrawal.release acts. */
    public const WITHDRAWALS_READ = 'withdrawal.release|wallet.view';

    /** Spec 015 reads: the code that acts, or wallet.view. */
    public const COMPENSATION_READ = 'compensation.pay|wallet.view';

    public const ADJUSTMENTS_READ = 'wallet.adjust|wallet.view';

    public const BANK_READ = 'bank.record|wallet.view';

    public const CLOSE_READ = 'day.close|wallet.view';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_VIEW => 'View customers and verification details',
            self::CUSTOMER_SUSPEND => 'Suspend or reinstate a customer',
            self::IDENTITY_VIEW => 'Open identity documents',
            self::IDENTITY_REVIEW => 'Approve or reject identity documents',
            self::STAFF_VIEW => 'View staff members and their roles',
            self::ROLES_MANAGE => 'Manage roles, their permissions, and staff role assignment',
            self::REFERENCE_VIEW => 'View karats and branches',
            self::KARATS_TOGGLE => 'Turn a karat on or off',
            self::KARATS_CREATE => 'Add a karat',
            self::BRANCHES_MANAGE => 'Add or edit branches, their hours and holidays',
            self::PRICING_VIEW => 'View gold prices, adjustments and settings',
            self::PRICING_RATES_MANAGE => 'Change commission, VAT, price adjustments and price rules',
            self::SETTINGS_MANAGE => 'Change deadlines, deposits and thresholds',
            self::GOLD_PRICE_ENTER => 'Enter a gold price manually',
            self::GOLD_PRICE_CONFIRM => 'Confirm a manual gold price',
            self::AUDIT_VIEW_ALL => 'View the whole audit log',
            self::AUDIT_VIEW_OWN => 'View your own actions in the audit log',
            self::WALLET_VIEW => 'View wallets and statements',
            self::TOPUP_MATCH => 'Match an incoming transfer',
            self::TOPUP_ACCOUNTS_MANAGE => "Manage Dahab's receiving accounts",
            self::LISTING_REVIEW => 'Approve or reject a new listing',
            self::LISTING_REQUEST_CHANGES => 'Ask a seller for a better photo',
            self::LISTING_TAKEDOWN => 'Take a live listing down',
            self::ORDER_CANCEL => 'Cancel an order',
            self::ORDER_VIEW => 'View orders',
            self::ORDER_RECEIVE => 'Mark a piece received at the branch',
            self::INSPECTION_ENTER => 'Enter an inspection result',
            self::ORDER_PRICE_ADJUST => 'Propose a new price after a regrade',
            self::ORDER_CHANGE_BRANCH => 'Change the inspection branch on an open order',
            self::ORDER_EXTEND_DEADLINE => 'Extend a deadline on request',
            self::ORDER_HANDOVER => 'Confirm handover at the counter',
            self::BUY_REQUEST_VIEW => 'View buy requests',
            self::WITHDRAWAL_RELEASE => 'Release a withdrawal',
            self::PAYOUT_ACCOUNT_VERIFY => 'Verify a payout bank account',
            self::DISPUTE_HANDLE => 'Handle disputes and freeze orders',
            self::ORDER_REFUND => 'Refund a buyer in full',
            self::COMPENSATION_PAY => 'Pay compensation to a wallet, up to the caps',
            self::COMPENSATION_UNCAPPED => 'Pay compensation above the caps',
            self::WALLET_ADJUST => 'Adjust a wallet balance directly',
            self::BANK_RECORD => 'Record a bank movement outside the app',
            self::DAY_CLOSE => 'Close the day',
            self::INVOICE_VIEW => 'View tax invoices and credit notes',
            self::INVOICE_CORRECT => 'Issue or correct a tax invoice',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::CUSTOMER_VIEW, self::CUSTOMER_SUSPEND, self::PAYOUT_ACCOUNT_VERIFY => 'Customers',
            self::IDENTITY_VIEW, self::IDENTITY_REVIEW => 'Identity',
            self::STAFF_VIEW, self::ROLES_MANAGE => 'Access control',
            self::REFERENCE_VIEW, self::KARATS_TOGGLE, self::KARATS_CREATE, self::BRANCHES_MANAGE => 'Reference data',
            self::PRICING_VIEW, self::PRICING_RATES_MANAGE, self::SETTINGS_MANAGE,
            self::GOLD_PRICE_ENTER, self::GOLD_PRICE_CONFIRM => 'Pricing',
            self::AUDIT_VIEW_ALL, self::AUDIT_VIEW_OWN => 'Audit',
            self::WALLET_VIEW, self::TOPUP_MATCH, self::TOPUP_ACCOUNTS_MANAGE, self::WITHDRAWAL_RELEASE,
            self::ORDER_REFUND, self::COMPENSATION_PAY, self::COMPENSATION_UNCAPPED,
            self::WALLET_ADJUST, self::BANK_RECORD, self::DAY_CLOSE,
            self::INVOICE_VIEW, self::INVOICE_CORRECT => 'Money',
            self::LISTING_REVIEW, self::LISTING_REQUEST_CHANGES, self::LISTING_TAKEDOWN => 'Listings',
            self::ORDER_CANCEL, self::ORDER_VIEW, self::ORDER_RECEIVE, self::INSPECTION_ENTER,
            self::ORDER_PRICE_ADJUST, self::ORDER_CHANGE_BRANCH, self::ORDER_EXTEND_DEADLINE,
            self::ORDER_HANDOVER, self::BUY_REQUEST_VIEW, self::DISPUTE_HANDLE => 'Orders',
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
            self::IDENTITY_REVIEW => [SeedRole::VERIFICATION->value],
            // Finance too (spec 008, 2026-09-28): it finds the customer whose wallet
            // it reads or whose transfer it matches. Seed only: existing installs
            // add it to Finance from Staff and permissions → Roles.
            self::CUSTOMER_VIEW => [SeedRole::VERIFICATION->value, SeedRole::FINANCE->value],
            // Both founders may change permissions (docs part1 §4.3 corrected reading).
            self::STAFF_VIEW,
            self::ROLES_MANAGE => [SeedRole::COO->value],
            // Spec 004 (Part 1 §4 matrix; not listed there = founders).
            self::REFERENCE_VIEW => [SeedRole::COO->value, SeedRole::FINANCE->value, SeedRole::OPERATIONS->value],
            self::KARATS_TOGGLE => [SeedRole::FINANCE->value],
            self::KARATS_CREATE => [SeedRole::COO->value],
            self::BRANCHES_MANAGE => [SeedRole::COO->value, SeedRole::OPERATIONS->value],
            // Spec 005: money-adjacent codes skip the COO (Part 1 §4.2).
            self::PRICING_VIEW => [SeedRole::COO->value, SeedRole::FINANCE->value, SeedRole::OPERATIONS->value],
            self::PRICING_RATES_MANAGE,
            self::GOLD_PRICE_ENTER,
            self::GOLD_PRICE_CONFIRM => [SeedRole::FINANCE->value],
            self::SETTINGS_MANAGE => [SeedRole::COO->value],
            // Spec 006 (Part 1 §4.3): the CEO sees everything; the others their own actions.
            self::AUDIT_VIEW_ALL => [],
            self::AUDIT_VIEW_OWN => [SeedRole::COO->value, SeedRole::FINANCE->value, SeedRole::OPERATIONS->value, SeedRole::VERIFICATION->value],
            // Spec 008: wallet-touching, so never the COO (Part 1 §4.2).
            self::WALLET_VIEW => [SeedRole::FINANCE->value],
            // Spec 009: wallet-touching, so never the COO (Part 1 §4.2).
            self::TOPUP_MATCH,
            self::TOPUP_ACCOUNTS_MANAGE => [SeedRole::FINANCE->value],
            // Spec 010 (Part 1 §4.1): CEO, COO and Operations; not wallet-touching.
            self::LISTING_REVIEW,
            self::LISTING_REQUEST_CHANGES,
            self::LISTING_TAKEDOWN => [SeedRole::COO->value, SeedRole::OPERATIONS->value],
            // Spec 011 (Part 1 §4.1 "Cancel an order"): CEO, COO and Operations. It refunds a
            // held deposit to its owner; it moves no money of Dahab's, so the COO keeps it.
            self::ORDER_CANCEL => [SeedRole::COO->value, SeedRole::OPERATIONS->value],
            // Spec 012 (Part 1 §4.1, research R22). Amounts on orders are not a wallet, so Finance reads them.
            self::ORDER_VIEW => [SeedRole::COO->value, SeedRole::FINANCE->value, SeedRole::OPERATIONS->value],
            // Not in the Part 1 matrix: the COO holds it too (everything but wallets, spec 002), so a
            // COO can still manage the Operations role that holds it.
            self::ORDER_RECEIVE => [SeedRole::COO->value, SeedRole::OPERATIONS->value, SeedRole::IGI_BRANCH->value],
            self::INSPECTION_ENTER, self::ORDER_HANDOVER => [SeedRole::IGI_BRANCH->value],
            self::ORDER_PRICE_ADJUST, self::ORDER_CHANGE_BRANCH, self::ORDER_EXTEND_DEADLINE,
            self::BUY_REQUEST_VIEW => [SeedRole::COO->value, SeedRole::OPERATIONS->value],
            // Spec 013 (Part 1 §4.2 "Release a withdrawal": CEO + Finance): wallet-touching, never the COO.
            self::WITHDRAWAL_RELEASE => [SeedRole::FINANCE->value],
            // Spec 013 (Part 1 §4.3 "Verify a payout bank account": CEO, Finance, Verification).
            self::PAYOUT_ACCOUNT_VERIFY => [SeedRole::FINANCE->value, SeedRole::VERIFICATION->value],
            // Spec 014 (Part 1 §4.1 "Freeze an order during a dispute": CEO, COO, Operations) plus
            // Finance, so the people who move the money can act on a dispute (analysis C1).
            self::DISPUTE_HANDLE => [SeedRole::COO->value, SeedRole::OPERATIONS->value, SeedRole::FINANCE->value],
            // Spec 014 (Part 1 §4.2 "Refund a buyer in full", "Pay compensation"): wallet-touching, never the COO.
            self::ORDER_REFUND, self::COMPENSATION_PAY => [SeedRole::FINANCE->value],
            // "CEO unlimited": no role; the CEO holds every code.
            self::COMPENSATION_UNCAPPED => [],
            // Spec 015 (Part 1 §4.2): "Adjust a wallet balance directly" is CEO only — no role;
            // "Record a bank movement" and "Close the day" are CEO + Finance. Never the COO.
            self::WALLET_ADJUST => [],
            self::BANK_RECORD, self::DAY_CLOSE => [SeedRole::FINANCE->value],
            // Spec 016 (Part 1 §4.2 "Issue or correct a tax invoice": CEO + Finance; reading the
            // same people, Clarifications). Never the COO.
            self::INVOICE_VIEW, self::INVOICE_CORRECT => [SeedRole::FINANCE->value],
        };
    }
}
