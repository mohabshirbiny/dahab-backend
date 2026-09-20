<?php

namespace App\Exceptions;

use App\Enums\AuthErrorCode;
use RuntimeException;

class AuthApiException extends RuntimeException
{
    public function __construct(
        public readonly AuthErrorCode $errorCode,
        public readonly int $statusCode,
        string $message = '',
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value);
    }

    /**
     * Thrown from a limiter's `->response()` callback when an identity bucket
     * (the account, not the IP) is exhausted. `$headers` carries Retry-After
     * and the X-RateLimit-* set built by the throttle middleware.
     */
    public static function accountLocked(array $headers = []): self
    {
        return new self(AuthErrorCode::ACCOUNT_LOCKED, 429, 'Too many requests.', headers: $headers);
    }

    public static function invalidCredentials(): self
    {
        return new self(AuthErrorCode::INVALID_CREDENTIALS, 401, 'Invalid credentials.');
    }

    public static function refreshInvalid(): self
    {
        return new self(AuthErrorCode::REFRESH_INVALID, 401, 'Refresh token is invalid.');
    }

    public static function permissionDenied(): self
    {
        return new self(AuthErrorCode::PERMISSION_DENIED, 403, 'Permission denied.');
    }

    public static function accountFrozen(): self
    {
        return new self(AuthErrorCode::ACCOUNT_FROZEN, 403, 'Account frozen.');
    }

    public static function mfaInvalid(): self
    {
        return new self(AuthErrorCode::MFA_INVALID, 401, 'Invalid verification code.');
    }

    public static function accountSuspended(?string $reason = null): self
    {
        return new self(AuthErrorCode::ACCOUNT_SUSPENDED, 403, 'Account suspended.', array_filter(['suspended_reason' => $reason]));
    }
}
