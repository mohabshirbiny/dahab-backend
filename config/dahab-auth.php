<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access & refresh token TTLs
    |--------------------------------------------------------------------------
    | Access tokens are short-lived; refresh tokens rotate on every use and
    | live 30 days by default. Values are minutes/days respectively.
    */

    'access_ttl_minutes' => (int) env('DAHAB_AUTH_ACCESS_TTL_MINUTES', 15),
    'refresh_ttl_days' => (int) env('DAHAB_AUTH_REFRESH_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | OTP challenge tuning
    |--------------------------------------------------------------------------
    */

    'otp' => [
        'ttl_seconds' => (int) env('DAHAB_AUTH_OTP_TTL_SECONDS', 300),
        'max_verify_attempts' => (int) env('DAHAB_AUTH_OTP_MAX_VERIFY_ATTEMPTS', 5),
        'send_cooldown_seconds' => (int) env('DAHAB_AUTH_OTP_SEND_COOLDOWN_SECONDS', 60),
        'max_sends_per_hour' => (int) env('DAHAB_AUTH_OTP_MAX_SENDS_PER_HOUR', 5),
        'code_length' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-step customer registration
    |--------------------------------------------------------------------------
    | How long an in-flight registration (start → OTP → complete) stays alive.
    | The state lives in the cache only; nothing is persisted until the final
    | step succeeds, so an expired session simply disappears.
    */

    'registration' => [
        'session_ttl_seconds' => (int) env('DAHAB_AUTH_REGISTRATION_SESSION_TTL_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiters (backing values for the RateLimiter::for() closures)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        // Registration has its own buckets: a burst of sign-ups from one phone
        // or IP must never lock that phone out of `customer_login`.
        'customer_register' => [
            'per_identity_max' => 5,
            'per_identity_window' => 60 * 60, // 1 hour, keyed by phone
            'per_ip_max' => 10,
            'per_ip_window' => 60 * 60,
        ],
        // Guessing the registration OTP, keyed by registration_ref and IP.
        'customer_register_otp' => [
            'per_session_max' => 5,
            'per_session_window' => 15 * 60,
            'per_ip_max' => 30,
            'per_ip_window' => 60 * 60,
        ],
        'customer_register_documents' => [
            'per_session_max' => 10,
            'per_session_window' => 15 * 60,
            'per_ip_max' => 30,
            'per_ip_window' => 60 * 60,
        ],
        'customer_login' => [
            'per_identity_max' => 5,
            'per_identity_window' => 15 * 60, // 15 min
            'per_ip_max' => 20,
            'per_ip_window' => 60,      // 1 min
        ],
        'staff_login' => [
            'per_identity_max' => 5,
            'per_identity_window' => 30 * 60, // 30 min
            'per_ip_max' => 20,
            'per_ip_window' => 60,
        ],
        // TOTP is 6 digits, so the guess budget is tiny: per pending session
        // (which lives 5 minutes) and per IP.
        'staff_mfa' => [
            'per_session_max' => 5,
            'per_session_window' => 5 * 60,
            'per_ip_max' => 20,
            'per_ip_window' => 60,
        ],
        'password_reset_request' => [
            'per_identity_max' => 3,
            'per_identity_window' => 60 * 60, // 1 hour
            'per_ip_max' => 20,
            'per_ip_window' => 60 * 60,
        ],
        'refresh' => [
            'per_family_max' => 60,
            'per_family_window' => 60 * 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff MFA requirement
    |--------------------------------------------------------------------------
    | Who must use MFA is data, not config (spec 002 FR-040): founders
    | (staff.is_founder) always, plus anyone holding a role flagged
    | `requires_mfa`. This switch only exists so local development can turn
    | the requirement off; it is forced on in production. Accounts that
    | enrolled voluntarily are always challenged.
    */

    'mfa_enforced' => (bool) env('DAHAB_AUTH_MFA_ENFORCED', true) || env('APP_ENV') === 'production',

    /*
    |--------------------------------------------------------------------------
    | Staff MFA (TOTP)
    |--------------------------------------------------------------------------
    | session_ttl_seconds — how long a `session_ref` (password accepted, TOTP
    |                       pending) stays valid.
    */

    'mfa' => [
        'session_ttl_seconds' => (int) env('DAHAB_AUTH_MFA_SESSION_TTL_SECONDS', 300),
        'recovery_code_count' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    */

    'password' => [
        'min_length' => 10,
        'check_pwned' => (bool) env('DAHAB_AUTH_PASSWORD_CHECK_PWNED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local development seed
    |--------------------------------------------------------------------------
    | Password for the per-role accounts created by LocalStaffSeeder. That
    | seeder only runs in the local/testing environments; never used elsewhere.
    */

    'local_seed_password' => env('DAHAB_LOCAL_STAFF_PASSWORD', 'seeded-password-1'),
];
