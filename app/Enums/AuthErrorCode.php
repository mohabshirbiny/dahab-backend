<?php

namespace App\Enums;

enum AuthErrorCode: string
{
    case VALIDATION_FAILED = 'validation_failed';
    case UNAUTHENTICATED = 'unauthenticated';
    case INVALID_CREDENTIALS = 'invalid_credentials';
    case ACCOUNT_SUSPENDED = 'account_suspended';
    case ACCOUNT_FROZEN = 'account_frozen';
    case ACCOUNT_LOCKED = 'account_locked';
    case PERMISSION_DENIED = 'permission_denied';
    case OTP_REQUIRED = 'otp_required';
    case OTP_INVALID = 'otp_invalid';
    case OTP_EXPIRED = 'otp_expired';
    // Multi-step registration: the `registration_ref` is unknown, expired or
    // already consumed; or the final step was called before the phone OTP.
    case REGISTRATION_SESSION_INVALID = 'registration_session_invalid';
    case REGISTRATION_SESSION_EXPIRED = 'registration_session_expired';
    case REGISTRATION_SESSION_CONSUMED = 'registration_session_consumed';
    case REGISTRATION_PHONE_UNVERIFIED = 'registration_phone_unverified';
    case REGISTRATION_EMAIL_UNVERIFIED = 'registration_email_unverified';
    case REGISTRATION_DOCUMENT_MISSING = 'registration_document_missing';
    case REGISTRATION_ALREADY_SUBMITTED = 'registration_already_submitted';
    case REGISTRATION_ENDPOINT_DEPRECATED = 'registration_endpoint_deprecated';
    case ACCOUNT_PENDING_VERIFICATION = 'account_pending_verification';
    case ACCOUNT_REJECTED = 'account_rejected';
    case MFA_REQUIRED = 'mfa_required';
    case MFA_ENROLLMENT_REQUIRED = 'mfa_enrollment_required';
    case MFA_INVALID = 'mfa_invalid';
    case TOKEN_INVALID = 'token_invalid';
    case TOKEN_EXPIRED = 'token_expired';
    case REFRESH_INVALID = 'refresh_invalid';
    case TOO_MANY_REQUESTS = 'too_many_requests';
    case NOT_FOUND = 'not_found';
    case SERVER_ERROR = 'server_error';
}
