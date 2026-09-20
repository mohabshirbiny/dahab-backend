# Auth Error Codes

Canonical list of `code` values returned by the auth surface. The set is exhaustive for this feature; adding a new code requires a spec amendment.

| Code | HTTP | When | Actor visibility |
|---|---|---|---|
| `validation_failed` | 422 | Request body fails FormRequest rules | Errors keyed by field |
| `invalid_credentials` | 401 | Wrong phone/email/password OR the target account is disabled (staff.is_active=false) | Identical shape for "wrong password" and "disabled account" to prevent enumeration |
| `unauthenticated` | 401 | Missing/expired/unknown token, OR a token of the other principal (a staff token on the Customer API, a customer token on the Dashboard API) | The guard (`auth:customer` / `auth:staff`) only resolves its own principal's tokens |
| `account_suspended` | 403 | Sign-in succeeded but action requires `trade_allowed` | Response includes `suspended_reason` on the customer's own `/customer/auth/me`, but never on refused actions |
| `account_frozen` | 403 | Staff sign-in refused due to open `account_freeze` row | |
| `account_locked` | 429 | The identity bucket of a login limiter (customer phone, staff email) is exhausted | `Retry-After` header set. Chosen by the limiter that tripped, not by the URL |
| `permission_denied` | 403 | Staff request refused by permission matrix | |
| `forbidden` | 403 | The token belongs to the right principal but lacks the ability the endpoint requires (a refresh token on an access endpoint, an access token on a refresh endpoint). Also the generic code for any other 403 that is not a permission denial | Body never says which ability is missing |
| `otp_required` | 200 (success envelope with `otp_required=true`) | New-device sign-in issued a challenge | |
| `otp_invalid` | 401 | Wrong OTP code | Attempts counter increments; challenge voided at 5 wrong |
| `otp_expired` | 401 | OTP TTL has elapsed | |
| `mfa_required` | 200 (success envelope with `mfa_required=true`) | Staff sign-in pending TOTP | |
| `mfa_enrollment_required` | 200 (success envelope with `mfa_enrollment_required=true`) | Founder/finance role has no `staff_mfa` row yet | |
| `mfa_invalid` | 401 | Wrong TOTP code | |
| `token_invalid` | 401 | Password-reset or email-verification token unknown/consumed | |
| `token_expired` | 401 | Password-reset or email-verification token past `expires_at` | |
| `refresh_invalid` | 401 | Refresh token replayed or family revoked | Whole family revoked as a safety measure |
| `too_many_requests` | 429 | Any other limiter tripped: customer registration, the per-IP bucket of a login limiter, OTP, password reset, refresh | `Retry-After` header set |
| `not_found` | 404 | Dashboard resource lookups (customer id) | |
| `server_error` | 500 | Unhandled exception; body omits detail unless `app.debug` |
