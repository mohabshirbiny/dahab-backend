<?php

namespace App\Enums;

enum AuditEvent: string
{
    case CUSTOMER_SIGN_IN = 'auth.customer.sign_in';
    case CUSTOMER_SIGN_IN_FAILED = 'auth.customer.sign_in_failed';
    case CUSTOMER_REGISTERED = 'auth.customer.registered';
    case CUSTOMER_OTP_SENT = 'auth.customer.otp_sent';
    case CUSTOMER_OTP_VERIFIED = 'auth.customer.otp_verified';
    case CUSTOMER_OTP_FAILED = 'auth.customer.otp_failed';
    case CUSTOMER_PASSWORD_RESET_REQUESTED = 'auth.customer.password_reset_requested';
    case CUSTOMER_PASSWORD_RESET_COMPLETED = 'auth.customer.password_reset_completed';
    case CUSTOMER_EMAIL_VERIFIED = 'auth.customer.email_verified';
    case CUSTOMER_SUSPENDED = 'auth.customer.suspended';
    case CUSTOMER_UNSUSPENDED = 'auth.customer.unsuspended';
    case STAFF_SIGN_IN = 'auth.staff.sign_in';
    case STAFF_SIGN_IN_FAILED = 'auth.staff.sign_in_failed';
    case STAFF_MFA_ENROLLED = 'auth.staff.mfa_enrolled';
    case STAFF_MFA_VERIFIED = 'auth.staff.mfa_verified';
    case STAFF_MFA_FAILED = 'auth.staff.mfa_failed';
    case STAFF_PASSWORD_RESET_COMPLETED = 'auth.staff.password_reset_completed';
    case STAFF_PERMISSION_DENIED = 'auth.staff.permission_denied';
    case IDENTITY_DOCUMENT_SUBMITTED = 'identity.document.submitted';
    case IDENTITY_DOCUMENT_VIEWED = 'identity.document.viewed';
    case IDENTITY_DOCUMENT_APPROVED = 'identity.document.approved';
    case IDENTITY_DOCUMENT_REJECTED = 'identity.document.rejected';
    case IDENTITY_DOCUMENT_RESUBMISSION_REQUESTED = 'identity.document.resubmission_requested';
    case IDENTITY_DOCUMENT_RESUBMITTED = 'identity.document.resubmitted';
    case CUSTOMER_REGISTRATION_SUBMITTED = 'auth.customer.registration_submitted';
    case CUSTOMER_VERIFICATION_APPROVED = 'auth.customer.verification_approved';
    case CUSTOMER_VERIFICATION_REJECTED = 'auth.customer.verification_rejected';
    case CUSTOMER_VERIFICATION_DETAILS_VIEWED = 'auth.customer.verification_details_viewed';
    case TOKEN_ROTATED = 'auth.token.rotated';
    case TOKEN_FAMILY_REVOKED = 'auth.token.family_revoked';
    case TOKEN_LOGOUT_ALL = 'auth.token.logout_all';
    case ROLE_CREATED = 'authz.role.created';
    case ROLE_UPDATED = 'authz.role.updated';
    case ROLE_PERMISSIONS_CHANGED = 'authz.role.permissions_changed';
    case ROLE_MFA_CHANGED = 'authz.role.mfa_changed';
    case ROLE_DELETED = 'authz.role.deleted';
    case STAFF_ROLES_CHANGED = 'authz.staff.roles_changed';
    case ESCALATION_DENIED = 'authz.escalation_denied';
    case RLS_SYSTEM_ELEVATION = 'rls.system_elevation';
    case RLS_MAINTENANCE_ELEVATION = 'rls.maintenance_elevation';
    case KARAT_CREATED = 'reference.karat.created';
    case KARAT_TOGGLED = 'reference.karat.toggled';
    case BRANCH_CREATED = 'reference.branch.created';
    case BRANCH_UPDATED = 'reference.branch.updated';
    case BRANCH_HOURS_REPLACED = 'reference.branch.hours_replaced';
    case CLOSURE_ADDED = 'reference.closure.added';
    case CLOSURE_REMOVED = 'reference.closure.removed';
    case STAFF_BRANCH_CHANGED = 'authz.staff.branch_changed';
    case SETTING_CHANGED = 'pricing.setting.changed';
    case ADJUSTMENT_CHANGED = 'pricing.adjustment.changed';
    case MANUAL_PRICE_ENTERED = 'pricing.manual_price.entered';
    case MANUAL_PRICE_CONFIRMED = 'pricing.manual_price.confirmed';
    case AUDIT_LOG_EXPORTED = 'audit.log.exported';
    case LEDGER_STATEMENT_VIEWED = 'ledger.statement.viewed';
    case LEDGER_STATEMENT_EXPORTED = 'ledger.statement.exported';
    case TOPUP_MATCHED = 'topup.matched';
    case TOPUP_CREDITED_BY_HAND = 'topup.credited_by_hand';
    case TOPUP_HELD = 'topup.held';
    case TOPUP_UNHELD = 'topup.unheld';
    case TOPUP_REJECTED = 'topup.rejected';
    case TOPUP_LIST_EXPORTED = 'topup.list_exported';
    case RECEIVING_ACCOUNT_CREATED = 'receiving_account.created';
    case RECEIVING_ACCOUNT_UPDATED = 'receiving_account.updated';
    case LISTING_APPROVED = 'listing.approved';
    case LISTING_CHANGES_REQUESTED = 'listing.changes_requested';
    case LISTING_REJECTED = 'listing.rejected';
    case LISTING_TAKEN_DOWN = 'listing.taken_down';
    case ORDER_CANCELLED = 'order.cancelled';

    // Spec 012: the order's life after acceptance.
    case ORDER_RECEIVED = 'order.received';
    case ORDER_BRANCH_CHANGED = 'order.branch_changed';
    case ORDER_DEADLINE_EXTENDED = 'order.deadline_extended';
    case ORDER_PRICE_PROPOSED = 'order.price_proposed';
    case ORDER_HANDED_OVER = 'order.handed_over';
    case ORDER_HANDOVER_FAILED = 'order.handover_failed';
    case ORDER_RETURN_HANDED_OVER = 'order.return_handed_over';
    case INSPECTION_RESULT_RECORDED = 'inspection.result_recorded';
    // Sweep effects (system actor).
    case ORDER_FORFEITED = 'order.forfeited';
    case ORDER_WINDOW_PASSED = 'order.window_passed';
    // Customer writes under the `order` scope (analysis C1): audited with the customer as actor.
    case ORDER_SELLER_CANCELLED = 'order.seller_cancelled';
    case ORDER_DECIDED = 'order.decided';
    case ORDER_PAID = 'order.paid';
    case ORDER_RELISTED = 'order.relisted';

    /** Plain words for the audit log viewer (spec 006). No default arm: a new case must get a label. */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER_SIGN_IN => 'Customer signed in',
            self::CUSTOMER_SIGN_IN_FAILED => 'Customer sign-in failed',
            self::CUSTOMER_REGISTERED => 'Customer registered',
            self::CUSTOMER_OTP_SENT => 'Verification code sent to a customer',
            self::CUSTOMER_OTP_VERIFIED => 'Customer verification code accepted',
            self::CUSTOMER_OTP_FAILED => 'Customer verification code refused',
            self::CUSTOMER_PASSWORD_RESET_REQUESTED => 'Customer asked to reset the password',
            self::CUSTOMER_PASSWORD_RESET_COMPLETED => 'Customer reset the password',
            self::CUSTOMER_EMAIL_VERIFIED => 'Customer email verified',
            self::CUSTOMER_SUSPENDED => 'Account suspended',
            self::CUSTOMER_UNSUSPENDED => 'Account reinstated',
            self::STAFF_SIGN_IN => 'Staff signed in',
            self::STAFF_SIGN_IN_FAILED => 'Staff sign-in failed',
            self::STAFF_MFA_ENROLLED => 'Authenticator app set up',
            self::STAFF_MFA_VERIFIED => 'Two-step code accepted',
            self::STAFF_MFA_FAILED => 'Two-step code refused',
            self::STAFF_PASSWORD_RESET_COMPLETED => 'Staff password reset',
            self::STAFF_PERMISSION_DENIED => 'Action refused: no permission',
            self::IDENTITY_DOCUMENT_SUBMITTED => 'Identity document submitted',
            self::IDENTITY_DOCUMENT_VIEWED => 'Identity document viewed',
            self::IDENTITY_DOCUMENT_APPROVED => 'Identity document approved',
            self::IDENTITY_DOCUMENT_REJECTED => 'Identity document rejected',
            self::IDENTITY_DOCUMENT_RESUBMISSION_REQUESTED => 'New identity document requested',
            self::IDENTITY_DOCUMENT_RESUBMITTED => 'Identity document sent again',
            self::CUSTOMER_REGISTRATION_SUBMITTED => 'Registration submitted',
            self::CUSTOMER_VERIFICATION_APPROVED => 'Customer verified',
            self::CUSTOMER_VERIFICATION_REJECTED => 'Customer verification rejected',
            self::CUSTOMER_VERIFICATION_DETAILS_VIEWED => 'Customer file opened for verification',
            self::TOKEN_ROTATED => 'Session refreshed',
            self::TOKEN_FAMILY_REVOKED => 'Session ended (reuse detected)',
            self::TOKEN_LOGOUT_ALL => 'Signed out everywhere',
            self::ROLE_CREATED => 'Role created',
            self::ROLE_UPDATED => 'Role renamed or described',
            self::ROLE_PERMISSIONS_CHANGED => 'Role permissions changed',
            self::ROLE_MFA_CHANGED => 'Role two-step requirement changed',
            self::ROLE_DELETED => 'Role deleted',
            self::STAFF_ROLES_CHANGED => "Staff member's roles changed",
            self::ESCALATION_DENIED => 'Access change refused (escalation)',
            self::RLS_SYSTEM_ELEVATION => 'System job ran with full data access',
            self::RLS_MAINTENANCE_ELEVATION => 'Maintenance command ran',
            self::KARAT_CREATED => 'Karat added',
            self::KARAT_TOGGLED => 'Karat turned on or off',
            self::BRANCH_CREATED => 'Branch added',
            self::BRANCH_UPDATED => 'Branch edited',
            self::BRANCH_HOURS_REPLACED => 'Branch hours changed',
            self::CLOSURE_ADDED => 'Holiday or closure added',
            self::CLOSURE_REMOVED => 'Holiday or closure removed',
            self::STAFF_BRANCH_CHANGED => "Staff member's branch changed",
            self::SETTING_CHANGED => 'Setting changed',
            self::ADJUSTMENT_CHANGED => 'Karat price adjustment changed',
            self::MANUAL_PRICE_ENTERED => 'Manual gold price entered',
            self::MANUAL_PRICE_CONFIRMED => 'Manual gold price confirmed',
            self::AUDIT_LOG_EXPORTED => 'Audit log exported',
            self::LEDGER_STATEMENT_VIEWED => 'Wallet statement opened',
            self::LEDGER_STATEMENT_EXPORTED => 'Wallet statement exported',
            self::TOPUP_MATCHED => 'Incoming transfer matched and credited',
            self::TOPUP_CREDITED_BY_HAND => 'Incoming transfer credited by hand',
            self::TOPUP_HELD => 'Incoming transfer put on hold',
            self::TOPUP_UNHELD => 'Incoming transfer taken off hold',
            self::TOPUP_REJECTED => 'Transfer notice rejected',
            self::TOPUP_LIST_EXPORTED => 'Incoming transfers exported',
            self::RECEIVING_ACCOUNT_CREATED => 'Receiving account added',
            self::RECEIVING_ACCOUNT_UPDATED => 'Receiving account changed',
            self::LISTING_APPROVED => 'Listing approved',
            self::LISTING_CHANGES_REQUESTED => 'Changes requested on a listing',
            self::LISTING_REJECTED => 'Listing rejected',
            self::LISTING_TAKEN_DOWN => 'Listing taken down',
            self::ORDER_CANCELLED => 'Acceptance cancelled',
            self::ORDER_RECEIVED => 'Piece received at the branch',
            self::ORDER_BRANCH_CHANGED => 'Order branch changed',
            self::ORDER_DEADLINE_EXTENDED => 'Order deadline extended',
            self::ORDER_PRICE_PROPOSED => 'New price proposed after a regrade',
            self::ORDER_HANDED_OVER => 'Piece handed over to the buyer',
            self::ORDER_HANDOVER_FAILED => 'Wrong collection code',
            self::ORDER_RETURN_HANDED_OVER => 'Returned piece handed back to the seller',
            self::INSPECTION_RESULT_RECORDED => 'Inspection result recorded',
            self::ORDER_FORFEITED => 'Deposit forfeited, the buyer did not pay',
            self::ORDER_WINDOW_PASSED => 'Storage window passed',
            self::ORDER_SELLER_CANCELLED => 'Seller cancelled the sale',
            self::ORDER_DECIDED => 'Buyer decided on an adjusted price',
            self::ORDER_PAID => 'Buyer paid the balance',
            self::ORDER_RELISTED => 'Seller relisted a returned piece',
        };
    }

    /** The viewer's category (spec 006). No default arm: a new case must get a category. */
    public function category(): AuditCategory
    {
        return match ($this) {
            self::CUSTOMER_SIGN_IN, self::CUSTOMER_SIGN_IN_FAILED, self::CUSTOMER_OTP_SENT, self::CUSTOMER_OTP_VERIFIED, self::CUSTOMER_OTP_FAILED, self::CUSTOMER_PASSWORD_RESET_REQUESTED, self::CUSTOMER_PASSWORD_RESET_COMPLETED, self::STAFF_SIGN_IN, self::STAFF_SIGN_IN_FAILED, self::STAFF_MFA_ENROLLED, self::STAFF_MFA_VERIFIED, self::STAFF_MFA_FAILED, self::STAFF_PASSWORD_RESET_COMPLETED, self::STAFF_PERMISSION_DENIED, self::TOKEN_ROTATED, self::TOKEN_FAMILY_REVOKED, self::TOKEN_LOGOUT_ALL => AuditCategory::SESSIONS,
            self::CUSTOMER_REGISTERED, self::CUSTOMER_EMAIL_VERIFIED, self::CUSTOMER_SUSPENDED, self::CUSTOMER_UNSUSPENDED, self::CUSTOMER_REGISTRATION_SUBMITTED, self::ROLE_CREATED, self::ROLE_UPDATED, self::ROLE_PERMISSIONS_CHANGED, self::ROLE_MFA_CHANGED, self::ROLE_DELETED, self::STAFF_ROLES_CHANGED, self::ESCALATION_DENIED, self::STAFF_BRANCH_CHANGED => AuditCategory::ACCOUNTS,
            self::IDENTITY_DOCUMENT_SUBMITTED, self::IDENTITY_DOCUMENT_VIEWED, self::IDENTITY_DOCUMENT_APPROVED, self::IDENTITY_DOCUMENT_REJECTED, self::IDENTITY_DOCUMENT_RESUBMISSION_REQUESTED, self::IDENTITY_DOCUMENT_RESUBMITTED, self::CUSTOMER_VERIFICATION_APPROVED, self::CUSTOMER_VERIFICATION_REJECTED, self::CUSTOMER_VERIFICATION_DETAILS_VIEWED => AuditCategory::IDENTITY,
            self::RLS_SYSTEM_ELEVATION, self::RLS_MAINTENANCE_ELEVATION, self::AUDIT_LOG_EXPORTED => AuditCategory::SYSTEM,
            self::KARAT_CREATED, self::KARAT_TOGGLED, self::BRANCH_CREATED, self::BRANCH_UPDATED, self::BRANCH_HOURS_REPLACED, self::CLOSURE_ADDED, self::CLOSURE_REMOVED => AuditCategory::REFERENCE,
            self::SETTING_CHANGED, self::ADJUSTMENT_CHANGED, self::MANUAL_PRICE_ENTERED, self::MANUAL_PRICE_CONFIRMED => AuditCategory::PRICING,
            self::LEDGER_STATEMENT_VIEWED, self::LEDGER_STATEMENT_EXPORTED,
            self::TOPUP_MATCHED, self::TOPUP_CREDITED_BY_HAND, self::TOPUP_HELD, self::TOPUP_UNHELD,
            self::TOPUP_REJECTED, self::TOPUP_LIST_EXPORTED,
            self::RECEIVING_ACCOUNT_CREATED, self::RECEIVING_ACCOUNT_UPDATED => AuditCategory::MONEY,
            self::LISTING_APPROVED, self::LISTING_CHANGES_REQUESTED, self::LISTING_REJECTED, self::LISTING_TAKEN_DOWN => AuditCategory::LISTINGS,
            self::ORDER_CANCELLED, self::ORDER_RECEIVED, self::ORDER_BRANCH_CHANGED, self::ORDER_DEADLINE_EXTENDED,
            self::ORDER_PRICE_PROPOSED, self::ORDER_HANDED_OVER, self::ORDER_HANDOVER_FAILED, self::ORDER_RETURN_HANDED_OVER,
            self::INSPECTION_RESULT_RECORDED, self::ORDER_SELLER_CANCELLED, self::ORDER_DECIDED, self::ORDER_PAID,
            self::ORDER_RELISTED, self::ORDER_FORFEITED, self::ORDER_WINDOW_PASSED => AuditCategory::ORDERS,
        };
    }

    /** @return list<string> the stored action codes of one category */
    public static function codesIn(AuditCategory $category): array
    {
        return array_values(array_map(
            fn (self $e) => $e->value,
            array_filter(self::cases(), fn (self $e) => $e->category() === $category),
        ));
    }
}
