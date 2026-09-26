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
}
